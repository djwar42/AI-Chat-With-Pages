<?php
// ai-chat-with-pages-settings.php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

require_once AICHWP_PLUGIN_DIR .'/vendor/autoload.php';

use Kambo\Langchain\LLMs\OpenAIChat;


// Register settings page
add_action('admin_menu', 'aichwp_register_settings_page');

function aichwp_register_settings_page() {
  add_menu_page(
      'AI Chat With Pages Settings', 
      'AI Chat With Pages',
      'manage_options', 
      'aichwp', 
      'aichwp_render_settings_page', 
      'dashicons-format-chat',
      25
  );
}

// Render settings page
function aichwp_render_settings_page() {
  if (!current_user_can('manage_options')) {
    return;
  }

  $options = get_option('aichwp_settings', array());
  if (empty($options['openai_api_key'])) {
      echo "<h2 style='margin-top: 15px; font-size: 16px; color: #3c82f6;'>Open AI API Key is not set, the chat app will not load on the front end until API Key is set.<br/>Please visit &rarr; <a style='text-decoration: underline;' href='https://platform.openai.com/api-keys'>Open AI</a> to create an api key.</h2>";

      aichwp_unschedule_initial_embeddings();
  } 
  else {
    try {
        $openAi = new OpenAIChat(['temperature' => 0.8]);
        $response = $openAi->generateResultString(['This is a test. Reply <true> and nothing else.']);

        $is_stale = get_option('aichwp_post_embeddings_are_stale');
        $is_running = get_option('aichwp_create_initial_embeddings_running');

        if ($is_stale == 1 && $is_running == 0) {
            aichwp_create_initial_embeddings();
            sleep(0.2);
        }
    } catch (Exception $e) {
        echo "<h2 style='margin-top: 15px; font-size: 18px; color: red;'>Open AI Key is not functioning correctly, please check the key for validity.</h2>";

        aichwp_unschedule_initial_embeddings();
    }
  }
  ?>
  
  <form action="options.php" method="post">
    <?php
    settings_fields('aichwp_settings'); 
    ?>
    <table class="form-table">
      <?php
      global $wp_settings_sections, $wp_settings_fields;

      if (!isset($wp_settings_sections['aichwp'])) {
        return;
      }

      foreach ((array) $wp_settings_sections['aichwp'] as $section) {
        if ($section['title']) {
          echo "<h2 style='font-size: 22px; margin-top: 30px;'>" . esc_html($section['title']) . "</h2>\n";
        }

        if ($section['callback']) {
          call_user_func($section['callback'], $section);
        }

        if (!isset($wp_settings_fields) || !isset($wp_settings_fields['aichwp']) || !isset($wp_settings_fields['aichwp'][$section['id']])) {
          continue;
        }

        echo '<table class="form-table">';
        do_settings_fields('aichwp', $section['id']);
        echo '</table>';
      }
      ?>
    </table>
    <?php
    submit_button();
    ?>
  </form>
  <div id="aichwp-chat-app" style="position: absolute; left: 520px; top: 540px;"></div>

  <?php
}

// Register settings
add_action('admin_init', 'aichwp_register_settings');

function aichwp_register_settings() {

  $options = get_option('aichwp_settings', array());
  $options_update_needed = false;

  register_setting(
    'aichwp_settings',
    'aichwp_settings',
    'aichwp_validate_settings'
  );

  add_settings_section('aichwp_main', 'Main Settings', 'aichwp_section_text', 'aichwp');

  // Add settings field for OpenAI API key
  add_settings_field('aichwp_openai_key', 'OpenAI API Key', 'aichwp_openai_key_field', 'aichwp', 'aichwp_main');

  // Add settings field for messages per hour limit
  add_settings_field('aichwp_messages_per_hour_limit', 'Max Messages Per Hour For Each User', 'aichwp_messages_per_hour_limit_field', 'aichwp', 'aichwp_main');

  // Add settings field for OpenAI chat model
  add_settings_field('aichwp_openai_chat_model', 'OpenAI Chat Model', 'aichwp_openai_chat_model_field', 'aichwp', 'aichwp_main');

  // Set default messages per hour limit
  $default_messages_per_hour_limit = 50;

  if (!isset($options['messages_per_hour_limit'])) {
    $options['messages_per_hour_limit'] = $default_messages_per_hour_limit;
    $options_update_needed = true;
  }

  // Set default OpenAI chat model
  $default_openai_chat_model = 'gpt-3.5-turbo';

  if (!isset($options['openai_chat_model'])) {
    $options['openai_chat_model'] = $default_openai_chat_model;
    $options_update_needed = true;
  }

  // Add settings section for indexing progress
  add_settings_section('aichwp_indexing_progress', 'Indexing', 'aichwp_indexing_progress_section_text', 'aichwp');

  // Add settings field for indexing progress indicator
  add_settings_field('aichwp_indexing_progress_indicator', '', 'aichwp_indexing_progress_indicator_field', 'aichwp', 'aichwp_indexing_progress');

  // Add settings section for colors
  add_settings_section('aichwp_colors', 'Colors', 'aichwp_colors_section_text', 'aichwp');

  // Set default colors
  $default_colors = array(
      'aichwpBgColor' => '#f3f4f6',
      'aichwpAIChatMessageBgColor' => '#3c82f6',
      'aichwpAIChatMessageTextColor' => '#ffffff',
      'aichwpUserChatMessageBgColor' => '#ffffff',
      'aichwpUserChatMessageTextColor' => '#001827',
      'aichwpChatClearChatTextColor' => '#4b5563',
      'aichwpUserAvatarColor' => '#001827',
      'aichwpLoadingIconColor' => '#3c82f6',
      'aichwpSendButtonColor' => '#3c82f6',
      'aichwpSendButtonTextColor' => '#ffffff',
      'aichwpChatOpenButtonColor' => '#3c82f6',
  );

  // Check each default color to see if it's already set
  foreach ($default_colors as $color => $default_value) {
      if (empty($options[$color])) {
          $options[$color] = $default_value;
          $options_update_needed = true; 
      }
  }

  // Add settings fields for each color
  add_settings_field('aichwp_bg_color', 'Bg Color', 'aichwp_color_field', 'aichwp', 'aichwp_colors', ['color' => 'aichwpBgColor']);
  add_settings_field('aichwp_ai_chat_message_bg_color', 'AI Chat Message Bg Color', 'aichwp_color_field', 'aichwp', 'aichwp_colors', ['color' => 'aichwpAIChatMessageBgColor']);
  add_settings_field('aichwp_ai_chat_message_text_color', 'AI Chat Message Text Color', 'aichwp_color_field', 'aichwp', 'aichwp_colors', ['color' => 'aichwpAIChatMessageTextColor']);
  add_settings_field('aichwp_user_chat_message_bg_color', 'User Chat Message Bg Color', 'aichwp_color_field', 'aichwp', 'aichwp_colors', ['color' => 'aichwpUserChatMessageBgColor']);
  add_settings_field('aichwp_user_chat_message_text_color', 'User Chat Message Text Color', 'aichwp_color_field', 'aichwp', 'aichwp_colors', ['color' => 'aichwpUserChatMessageTextColor']);
  add_settings_field('aichwp_clear_chat_text_color', 'Clear Chat Text Color', 'aichwp_color_field', 'aichwp', 'aichwp_colors', ['color' => 'aichwpChatClearChatTextColor']);
  add_settings_field('aichwp_user_avatar_color', 'User Avatar Color', 'aichwp_color_field', 'aichwp', 'aichwp_colors', ['color' => 'aichwpUserAvatarColor']);
  add_settings_field('aichwp_loading_icon_color', 'Loading Icon Color', 'aichwp_color_field', 'aichwp', 'aichwp_colors', ['color' => 'aichwpLoadingIconColor']);
  add_settings_field('aichwp_send_button_color', 'Send Button Color', 'aichwp_color_field', 'aichwp', 'aichwp_colors', ['color' => 'aichwpSendButtonColor']);
  add_settings_field('aichwp_send_button_text_color', 'Send Button Text Color', 'aichwp_color_field', 'aichwp', 'aichwp_colors', ['color' => 'aichwpSendButtonTextColor']);
  add_settings_field('aichwp_chat_open_button_color', 'Chat Open Button Color', 'aichwp_color_field', 'aichwp', 'aichwp_colors', ['color' => 'aichwpChatOpenButtonColor']);

  // Add section for post types
  add_settings_section('aichwp_post_types', 'Post Types', 'aichwp_post_types_section_text', 'aichwp');

  $post_types = aichwp_get_post_types();
  foreach ($post_types as $post_type) {
      $post_type_object = get_post_type_object($post_type);
      add_settings_field(
          'aichwp_post_type_' . $post_type,
          $post_type_object->label,
          'aichwp_post_type_field',
          'aichwp',
          'aichwp_post_types',
          ['post_type' => $post_type]
      );

      add_settings_field(
          'aichwp_post_meta_fields_' . $post_type,
          '',
          'aichwp_post_meta_fields_field',
          'aichwp',
          'aichwp_post_types',
          ['post_type' => $post_type]
      );
  }

  // Add settings section for chat options
  add_settings_section('aichwp_chat_options', 'Chat Options', 'aichwp_chat_options_section_text', 'aichwp');

  // Add settings field for chat welcome message
  add_settings_field('aichwp_chat_welcome_message', 'Chat Welcome Message', 'aichwp_chat_welcome_message_field', 'aichwp', 'aichwp_chat_options');

  // Add settings fields for initial suggested questions
  add_settings_field('aichwp_initial_suggested_question_1', 'Initial Suggested Question 1', 'aichwp_initial_suggested_question_field', 'aichwp', 'aichwp_chat_options', ['question_number' => 1]);
  add_settings_field('aichwp_initial_suggested_question_2', 'Initial Suggested Question 2', 'aichwp_initial_suggested_question_field', 'aichwp', 'aichwp_chat_options', ['question_number' => 2]);
  add_settings_field('aichwp_initial_suggested_question_3', 'Initial Suggested Question 3', 'aichwp_initial_suggested_question_field', 'aichwp', 'aichwp_chat_options', ['question_number' => 3]);

  // Set default chat welcome message
  $default_chat_welcome_message = "Hi, I am an artificial intelligence assistant who can answer any queries you may have about content on this site. Type your question in the area below to begin.";

  if (empty($options['chat_welcome_message'])) {
    $options['chat_welcome_message'] = $default_chat_welcome_message;
    $options_update_needed = true;
  }

  if ($options_update_needed) {
    update_option('aichwp_settings', $options);
  }
}

// Section text
function aichwp_section_text() {
  echo 'Configure the main AI Chat With Pages settings below:';
}

// Indexing progress section text
function aichwp_indexing_progress_section_text() {
  echo 'Site content indexing:';
}

// Output indexing progress indicator field
function aichwp_indexing_progress_indicator_field() {
  $options = get_option('aichwp_settings', array());

  if (!isset($options['openai_api_key']) || empty($options['openai_api_key'])) {
    echo '<span id="aichwp_indexing_status">&nbsp;Please set your OpenAI API Key above.</span>';
  } else {
    echo '<span id="aichwp_indexing_status">'. esc_html(aichwp_get_total_indexed_documents()) .' documents indexed.</span>';
  }
}

// Get the total number of indexed documents
function aichwp_get_total_indexed_documents() {
  global $wpdb;
  $total_indexed = $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->prefix}aichat_post_embeddings WHERE is_active = 1");
  return intval($total_indexed);
}

// Output messages per hour limit field
function aichwp_messages_per_hour_limit_field() {
  $options = get_option('aichwp_settings', array());
  $messages_per_hour_limit = isset($options['messages_per_hour_limit']) ? intval($options['messages_per_hour_limit']) : 50;

  echo '<input type="number" name="aichwp_settings[messages_per_hour_limit]" value="' . esc_attr($messages_per_hour_limit) . '" min="0" />';
}

// Output OpenAI chat model field
function aichwp_openai_chat_model_field() {
  $options = get_option('aichwp_settings', array());
  $openai_chat_model = isset($options['openai_chat_model']) ? esc_attr($options['openai_chat_model']) : 'gpt-3.5-turbo';

  echo '<select name="aichwp_settings[openai_chat_model]">';
  echo '<option value="gpt-3.5-turbo"' . selected($openai_chat_model, 'gpt-3.5-turbo', false) . '>gpt-3.5-turbo</option>';
  echo '<option value="gpt-4-turbo"' . selected($openai_chat_model, 'gpt-4-turbo', false) . '>gpt-4-turbo</option>';
  echo '<option value="gpt-4o"' . selected($openai_chat_model, 'gpt-4o', false) . '>gpt-4o</option>';
}

// Colors section text
function aichwp_colors_section_text() {
  echo 'Set the colors for the chat interface:';
}

// Output color picker field
function aichwp_color_field($args) {
  $options = get_option('aichwp_settings', array());
  $color = $args['color'];
  $value = isset($options[$color]) ? esc_attr($options[$color]) : '';

  echo '<input type="text" name="aichwp_settings[' . esc_attr($color) . ']" value="' . esc_attr($value) . '" class="aichwp-color-picker" />';
  echo '<a href="#" class="aichwp-reset-color" data-color="' . esc_attr($color) . '">Reset to default</a>';
}

// Output API key field
function aichwp_openai_key_field() {
  $options = get_option('aichwp_settings', array());
  
  if (!isset($options['openai_api_key'])) {
    $options['openai_api_key'] = '';
  }

  echo '<input type="text" name="aichwp_settings[openai_api_key]" value="' . esc_attr($options['openai_api_key']) . '" />';
}

// Post types section text
function aichwp_post_types_section_text() {
  echo 'Select the post types to include in the search:';
}

// Get all post types
function aichwp_get_post_types() {
  $post_types = get_post_types(['public' => true], 'names');
  $post_types[] = 'wp_template';
  unset($post_types['attachment']);
  return $post_types;
}

// Output post type checkbox field
function aichwp_post_type_field($args) {
  $options = get_option('aichwp_settings', array());
  $post_type = $args['post_type'];
  $checked = isset($options['post_types'][$post_type]) ? checked($options['post_types'][$post_type], 1, false) : 'checked';

  echo '<input type="checkbox" name="aichwp_settings[post_types][' . esc_attr($post_type) . ']" value="1" ' . esc_attr($checked) . ' />';
}

// Chat options section text
function aichwp_chat_options_section_text() {
  echo 'Configure the chat options below:';
}

// Output chat welcome message field
function aichwp_chat_welcome_message_field() {
  $options = get_option('aichwp_settings', array());
  $chat_welcome_message = isset($options['chat_welcome_message']) ? esc_textarea($options['chat_welcome_message']) : '';

  echo '<textarea name="aichwp_settings[chat_welcome_message]" rows="4" cols="50">' . esc_textarea($chat_welcome_message) . '</textarea>';
}

// Output initial suggested question field
function aichwp_initial_suggested_question_field($args) {
  $options = get_option('aichwp_settings', array());
  $question_number = $args['question_number'];
  $initial_suggested_question = isset($options['initial_suggested_question_' . $question_number]) ? esc_textarea($options['initial_suggested_question_' . $question_number]) : '';

  echo '<textarea name="aichwp_settings[initial_suggested_question_' . esc_attr($question_number) . ']" rows="2" cols="50">' . esc_textarea($initial_suggested_question) . '</textarea>';
}

// Output post meta fields checkbox field
function aichwp_post_meta_fields_field($args) {
    $options = get_option('aichwp_settings', array());
    $post_type = $args['post_type'];

    $sample_post = get_posts([
        'post_type' => $post_type,
        'posts_per_page' => 1,
    ]);

    if (!empty($sample_post)) {
        $meta_fields = get_post_custom($sample_post[0]->ID);
        $post_type_object = get_post_type_object($post_type);

        echo '<div class="aichwp-post-meta-fields-container" style="margin-top: -20px;">';
        echo '<a href="#" class="aichwp-toggle-meta-fields" data-post-type="' . esc_attr($post_type) . '">Select ' .esc_attr($post_type_object->label). ' meta fields +</a>';
        echo '<div class="aichwp-post-meta-fields-list" data-post-type="' . esc_attr($post_type) . '" style="display: none; margin-top: 10px;">';

        if (!empty($meta_fields)) {
            foreach ($meta_fields as $meta_key => $meta_value) {
                $checked = isset($options['post_meta_fields'][$post_type][$meta_key]) ? 'checked' : '';
                echo '<label>';
                echo '<input type="checkbox" name="aichwp_settings[post_meta_fields][' . esc_attr($post_type) . '][' . esc_attr($meta_key) . ']" value="1" ' . esc_attr($checked) . ' />';
                echo esc_html($meta_key);
                echo '</label><br>';
            }
        } else {
            echo '<p>No meta fields exist.</p>';
        }

        echo '</div>';
        echo '</div>';
    }
}

// Validate input
function aichwp_validate_settings($input) {
  $output = array();

  // Validate OpenAI api key
  if (isset($input['openai_api_key'])) {
    $output['openai_api_key'] = sanitize_text_field($input['openai_api_key']);
  }

  // Validate messages per hour limit
  $input['messages_per_hour_limit'] = intval($input['messages_per_hour_limit']);
  if ($input['messages_per_hour_limit'] < 0) {
    $input['messages_per_hour_limit'] = 50;
  }
  $output['messages_per_hour_limit'] = sanitize_text_field($input['messages_per_hour_limit']);

  // Validate OpenAI chat model
  $valid_models = array('gpt-3.5-turbo', 'gpt-4-turbo', 'gpt-4o');
  if (!in_array($input['openai_chat_model'], $valid_models)) {
    $input['openai_chat_model'] = 'gpt-3.5-turbo';
  }
  $output['openai_chat_model'] = sanitize_text_field($input['openai_chat_model']);

  // Validate post types
  $post_types = aichwp_get_post_types();
  foreach ($post_types as $post_type) {
      if (isset($input['post_types'][$post_type])) {
          $output['post_types'][$post_type] = 1;
      } else {
          // If the post type is not in the input array, set it as unchecked
          $output['post_types'][$post_type] = 0;
      }

      // Validate post meta fields for each post type
      if (isset($input['post_meta_fields'][$post_type])) {
          foreach ($input['post_meta_fields'][$post_type] as $meta_key => $meta_value) {
              $sanitized_meta_key = sanitize_key($meta_key);
              $output['post_meta_fields'][$post_type][$sanitized_meta_key] = 1;
          }
      }
  }

  // Validate colors
  $output['aichwpBgColor'] = sanitize_text_field($input['aichwpBgColor']);
  $output['aichwpAIChatMessageBgColor'] = sanitize_text_field($input['aichwpAIChatMessageBgColor']);
  $output['aichwpAIChatMessageTextColor'] = sanitize_text_field($input['aichwpAIChatMessageTextColor']);
  $output['aichwpUserChatMessageBgColor'] = sanitize_text_field($input['aichwpUserChatMessageBgColor']);
  $output['aichwpUserChatMessageTextColor'] = sanitize_text_field($input['aichwpUserChatMessageTextColor']);
  $output['aichwpChatClearChatTextColor'] = sanitize_text_field($input['aichwpChatClearChatTextColor']);
  $output['aichwpUserAvatarColor'] = sanitize_text_field($input['aichwpUserAvatarColor']);
  $output['aichwpLoadingIconColor'] = sanitize_text_field($input['aichwpLoadingIconColor']);
  $output['aichwpSendButtonColor'] = sanitize_text_field($input['aichwpSendButtonColor']);
  $output['aichwpSendButtonTextColor'] = sanitize_text_field($input['aichwpSendButtonTextColor']);
  $output['aichwpChatOpenButtonColor'] = sanitize_text_field($input['aichwpChatOpenButtonColor']);

  // Validate chat welcome message
  $output['chat_welcome_message'] = sanitize_textarea_field($input['chat_welcome_message']);

  // Validate initial suggested questions
  if (isset($input['initial_suggested_question_1'])) {
    $output['initial_suggested_question_1'] = sanitize_textarea_field($input['initial_suggested_question_1']);
  }
  if (isset($input['initial_suggested_question_2'])) {
    $output['initial_suggested_question_2'] = sanitize_textarea_field($input['initial_suggested_question_2']);
  }
  if (isset($input['initial_suggested_question_3'])) {
    $output['initial_suggested_question_3'] = sanitize_textarea_field($input['initial_suggested_question_3']);
  }

  return $output;
}

// Enqueue scripts and styles for the plugin's admin page
add_action('admin_enqueue_scripts', 'aichwp_admin_enqueue_scripts');

function aichwp_admin_enqueue_scripts($hook) {
  if ('toplevel_page_aichwp' != $hook) {
    return;
  }

  wp_enqueue_style('wp-color-picker');
  wp_enqueue_script('wp-color-picker');

  wp_enqueue_script('aichwp-admin-script', AICHWP_PLUGIN_URL . 'core/js/admin-script.js', ['jquery', 'wp-color-picker'], '1.0', true);

  wp_enqueue_style('aichwp-chat-style', AICHWP_PLUGIN_URL . 'core/js/chat-app/build/static/css/aichwp.css', [], '1.0');
  wp_enqueue_script('aichwp-chat-script', AICHWP_PLUGIN_URL . 'core/js/chat-app/build/static/js/aichwp.js', ['jquery'], '1.0', true);

  $options = get_option('aichwp_settings', array());
  $color_vars = [
    'aichwpBgColor' => $options['aichwpBgColor'] ?? '#f3f4f6',
    'aichwpAIChatMessageBgColor' => $options['aichwpAIChatMessageBgColor'] ?? '#3c82f6',
    'aichwpAIChatMessageTextColor' => $options['aichwpAIChatMessageTextColor'] ?? '#ffffff',
    'aichwpUserChatMessageBgColor' => $options['aichwpUserChatMessageBgColor'] ?? '#ffffff',
    'aichwpUserChatMessageTextColor' => $options['aichwpUserChatMessageTextColor'] ?? '#001827',
    'aichwpChatClearChatTextColor' => $options['aichwpChatClearChatTextColor'] ?? '#4b5563',
    'aichwpUserAvatarColor' => $options['aichwpUserAvatarColor'] ?? '#001827',
    'aichwpLoadingIconColor' => $options['aichwpLoadingIconColor'] ?? '#3c82f6',
    'aichwpSendButtonColor' => $options['aichwpSendButtonColor'] ?? '#3c82f6',
    'aichwpSendButtonTextColor' => $options['aichwpSendButtonTextColor'] ?? '#ffffff',
    'aichwpChatOpenButtonColor' => $options['aichwpChatOpenButtonColor'] ?? '#3c82f6',
  ];

  wp_localize_script('aichwp-chat-script', 'aichwp_color_vars', $color_vars);

  $chat_options = [
      'chat_welcome_message' => $options['chat_welcome_message'] ?? '',
      'initial_suggested_question_1' => $options['initial_suggested_question_1'] ?? '',
      'initial_suggested_question_2' => $options['initial_suggested_question_2'] ?? '',
      'initial_suggested_question_3' => $options['initial_suggested_question_3'] ?? '',
  ];
  wp_localize_script('aichwp-chat-script', 'aichwp_chat_vars', $chat_options);

  $aichwp_chat_nonce = wp_create_nonce('aichwp_chat_nonce');
  wp_localize_script('aichwp-chat-script', 'aichwp_chat_nonce', $aichwp_chat_nonce);

  wp_localize_script('aichwp-admin-script', 'aichwp_ajax', [
    'ajax_url' => admin_url('admin-ajax.php'),
    'plugin_url' => AICHWP_PLUGIN_URL
  ]);
}