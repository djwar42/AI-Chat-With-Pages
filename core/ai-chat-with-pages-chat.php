<?php
// ai-chat-with-pages-chat.php
require_once AICHWP_PLUGIN_DIR .'/vendor/autoload.php';

use Kambo\Langchain\VectorStores\SimpleStupidVectorStore\SimpleStupidVectorStore;
use Kambo\Langchain\VectorStores\SimpleStupidVectorStore\Collection;
use Kambo\Langchain\Embeddings\OpenAIEmbeddings;
use Kambo\Langchain\LLMs\OpenAIChat;
use Kambo\Langchain\Memory\ConversationBufferWindowMemory;
use Kambo\Langchain\Message\HumanMessage;
use Kambo\Langchain\Message\AIMessage;
use Kambo\Langchain\LLMs\LLMResult;


add_action('wp_ajax_aichwp_chat', 'aichwp_chat_handler');
add_action('wp_ajax_nopriv_aichwp_chat', 'aichwp_chat_handler');

function aichwp_chat_handler() {
  // Retrieve the query and history from the AJAX request
  $query = isset($_POST['query']) ? $_POST['query'] : '';
  $history = isset($_POST['history']) ? json_decode(stripslashes($_POST['history']), true) : [];

  // Limit the query to 5000 characters
  $query = substr($query, 0, 5000);

  $options = get_option('aichwp_settings', array());

  // Check if the OpenAI API key is set
  if (empty($options['openai_api_key'])) {
      $response_data = [
        'query' => $query,
        'response' => 'The ai chat with pages plugin requires an API key to be set up.',
        'references' => [],
        'history' => json_encode(['history' => []]),
        'chat_in_progress' => false,
      ];
      wp_send_json_success($response_data);
      return;
  }

  // Check messages per hour
  $messages_per_hour_limit = isset($options['messages_per_hour_limit']) ? intval($options['messages_per_hour_limit']) : 30;

  // Create the user hash based on IP and browser client
  $user_ip = $_SERVER['REMOTE_ADDR'];
  $user_agent = $_SERVER['HTTP_USER_AGENT'];
  $user_hash = md5($user_ip . $user_agent);

  // Get the current hour
  $current_hour = date('YmdH');

  global $wpdb;
  $user_messages_table = $wpdb->prefix . 'aichat_user_messages';

  // Remove entries older than 48 hours
  $forty_eight_hours_ago = date('YmdH', strtotime('-48 hours'));
  $wpdb->query($wpdb->prepare("DELETE FROM $user_messages_table WHERE latest_hour < %d", $forty_eight_hours_ago));

  // Check if the user hash exists in the table
  $user_messages = $wpdb->get_row($wpdb->prepare("SELECT * FROM $user_messages_table WHERE user_hash = %s", $user_hash));

  if ($user_messages) {
      // User hash exists, update the message count and latest hour
      if ($user_messages->latest_hour == $current_hour) {
          // Same hour, increment the message count
          $new_messages = $user_messages->messages + 1;
      } else {
          // New hour, reset the message count
          $new_messages = 1;
      }

      $wpdb->update(
          $user_messages_table,
          array(
              'messages' => $new_messages,
              'latest_hour' => $current_hour
          ),
          array('user_hash' => $user_hash)
      );
  } else {
      // User hash doesn't exist, insert a new row
      $wpdb->insert(
          $user_messages_table,
          array(
              'user_hash' => $user_hash,
              'messages' => 1,
              'latest_hour' => $current_hour
          )
      );
      $new_messages = 1;
  }

  // Check if the messages per hour limit is exceeded
  if ($new_messages > $messages_per_hour_limit) {
      $response_data = [
          'query' => $query,
          'response' => 'You have exceeded your messages for this hour, please wait some time and try again.',
          'history' => json_encode(['history' => $history]),
          'chat_in_progress' => false,
      ];
      wp_send_json_success($response_data);
      return;
  }

  // Get the selected post types from the options
  $selectedPostTypes = isset($options['post_types']) ? array_keys($options['post_types'], 1) : [];

  // Index and AI setup
  $openAi = new OpenAIChat(['temperature' => 0.8]);

  $vectorstore = new SimpleStupidVectorStore([]);
  $collection = $vectorstore->getOrCreateCollection('posts', []);
  $embeddings = new OpenAIEmbeddings([]);
  $queryEmbedding = $embeddings->embedQuery($query);

  $ragResults = $collection->similaritySearchWithScore($queryEmbedding, 4, $selectedPostTypes);

  //error_log(print_r($ragResults, true));
  $concatenatedDocuments = '';
  $processedPostIds = array();

  $concatenatedDocuments = '';
  $processedPostIds = array();

  foreach ($ragResults as $result) {
    $postId = $result[0]['metadata']['post_id'];

    // Check if the post ID has already been processed
    if (in_array($postId, $processedPostIds)) {
        continue;
    }

    $post = get_post($postId);

    if ($post) {
        $postTitle = $post->post_title;
        $postType = $post->post_type;
        $postContent = $post->post_content;
        $documentPassage = $result[0]['document'];

        // Remove the prepended "PostTitle: $post->post_title\n" from the document passage
        $documentPassage = preg_replace("/^PostTitle: .+\n/", "", $documentPassage);

        // Apply the same transformations to the post content
        $transformedPostContent = trim(preg_replace("/\n\s*\n/", "\n", strip_tags($postContent)));

        // Find the position of the document passage within the transformed post content
        $position = strpos($transformedPostContent, $documentPassage);

        if ($position !== false) {
            // Get 1000 characters before and after the document passage
            $startPosition = max(0, $position - 1000);
            $endPosition = min(strlen($transformedPostContent), $position + strlen($documentPassage) + 1000);
            $excerptContent = substr($transformedPostContent, $startPosition, $endPosition - $startPosition);

            // Add the document to the concatenated string
            $concatenatedDocuments .= "--\n";
            $concatenatedDocuments .= "Post Title: " . $postTitle . "\n";
            $concatenatedDocuments .= "Post Type: " . $postType . "\n\n";
            $concatenatedDocuments .= $excerptContent . "\n\n";

            // Add the post ID to the processed post IDs array
            $processedPostIds[] = $postId;
        } else {
            // If no match is found, use the $result[0]['document'] content
            $concatenatedDocuments .= "--\n";
            $concatenatedDocuments .= "Post Title: " . $postTitle . "\n";
            $concatenatedDocuments .= "Post Type: " . $postType . "\n\n";
            $concatenatedDocuments .= $documentPassage . "\n\n";

            // Add the post ID to the processed post IDs array
            $processedPostIds[] = $postId;
        }
    }
  }

  // Generate the conversation history buffer
  $conversationHistoryBuffer = '';
  $characterCount = 0;
  $maxCharacters = 5000;

  if (isset($history)) {
      $reversedHistory = array_reverse($history);

      foreach ($reversedHistory as $item) {
          if (isset($item['role']) && isset($item['content'])) {
              $message = $item['role'] . ': ' . $item['content'] . "\n";
              $messageLength = strlen($message);

              if ($characterCount + $messageLength <= $maxCharacters) {
                  $conversationHistoryBuffer = $message . $conversationHistoryBuffer;
                  $characterCount += $messageLength;
              } else {
                  break;
              }
          }
      }
  }

  $conversationHistoryBuffer = strip_tags($conversationHistoryBuffer);

  // Generate the prompt
  $prompt = <<<EOD
You are a helpful chat assistant running on a wordpress website tasked with answering user queries about the content pages on the site, which will be provided to you in <context> tags. Try and avoid answering questions un-related to the content on the site. If you are unable to find a good answer, say you don't know.

<conversationhistory>
$conversationHistoryBuffer
</conversationhistory>

Use the following pieces of context to give a helpful answer to the question following:
<context>
$concatenatedDocuments
</context>

Question: $query
EOD;
  //error_log("--- PROMPT ---\n" . $prompt);

  // Query the index and generate the response
  $response = $openAi->generateResultString([$prompt]);

  // Find the top source document with a good score
  $topSourceDocument = null;
  foreach ($ragResults as $doc) {
      if ($doc['score'] > 0.37) {
          $topSourceDocument = $doc;
          break;
      }
  }

  if ($topSourceDocument !== null) {
    $postTitle = $topSourceDocument[0]['metadata']['post_title'];
    $postGuid = $topSourceDocument[0]['metadata']['post_guid'];
    $response .= "<br/><a href='{$postGuid}'>&rarr; {$postTitle}</a>";
  }

  // Append the new query and response to the history
  $history[] = ['role' => 'Human', 'content' => $query];
  $history[] = ['role' => 'AI', 'content' => $response];

  // Prepare the response data
  $response_data = [
      'query' => $query,
      'response' => $response,
      'history' => json_encode([
          'history' => $history
      ]),
      'chat_in_progress' => true,
  ];

  // Send the response as JSON
  wp_send_json_success($response_data);
}

/**
 * Register chat widget
*/
add_action('wp_enqueue_scripts', 'aichwp_enqueue_scripts');
function aichwp_enqueue_scripts() {
  wp_enqueue_style('aichwp-chat-style', AICHWP_PLUGIN_URL . 'core/js/chat-app/build/static/css/aichwp.css', [], '1.0');
  wp_enqueue_script('aichwp-chat-script', AICHWP_PLUGIN_URL . 'core/js/chat-app/build/static/js/aichwp.js', ['jquery'], '1.0', true);

  $options = get_option('aichwp_settings', array());
  $color_vars = [
    'aichwpBgColor' => $options['aichwpBgColor'] ?? '#f3f4f6',
    'aichwpAIChatMessageBgColor' => $options['aichwpAIChatMessageBgColor'] ?? '#3c82f6',
    'aichwpAIChatMessageTextColor' => $options['aichwpAIChatMessageTextColor'] ?? '#ffffff',
    'aichwpUserChatMessageBgColor' => $options['aichwpUserChatMessageBgColor'] ?? '#ffffff',
    'aichwpUserChatMessageTextColor' => $options['aichwpUserChatMessageTextColor'] ?? '#001827',
    'aichwpChatClearChatTextColor' => $options['aichwpChatClearChatTextColor'] ?? '#001827',
    'aichwpUserAvatarColor' => $options['aichwpUserAvatarColor'] ?? '#001827',
    'aichwpLoadingIconColor' => $options['aichwpLoadingIconColor'] ?? '#3c82f6',
    'aichwpSendButtonColor' => $options['aichwpSendButtonColor'] ?? '#3c82f6',
    'aichwpSendButtonTextColor' => $options['aichwpSendButtonTextColor'] ?? '#ffffff',
    'aichwpChatOpenButtonColor' => $options['aichwpChatOpenButtonColor'] ?? '#3c82f6',
  ];

  $chat_vars = [
    'chat_welcome_message' => $options['chat_welcome_message'] ?? '',
    'initial_suggested_question_1' => $options['initial_suggested_question_1'] ?? '',
    'initial_suggested_question_2' => $options['initial_suggested_question_2'] ?? '',
    'initial_suggested_question_3' => $options['initial_suggested_question_3'] ?? '',
  ];

  wp_localize_script('aichwp-chat-script', 'aichwp_color_vars', $color_vars);
  wp_localize_script('aichwp-chat-script', 'aichwp_chat_vars', $chat_vars);
  wp_localize_script('aichwp-chat-script', 'aichwp_ajax', [
    'ajax_url' => admin_url('admin-ajax.php')
  ]);
}

/**
 * Append the chat widget div to the HTML
 */
add_action('wp_footer', 'aichwp_append_chat_app_div');

function aichwp_append_chat_app_div() {
    ?>
    <div id="aichwp-chat-app"></div>
    <?php
}
