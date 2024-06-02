<?php
// ai-chat-with-pages-indexing.php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

require_once AICHWP_PLUGIN_DIR . '/plugins/action-scheduler/action-scheduler.php';
require_once AICHWP_PLUGIN_DIR .'/vendor/autoload.php';

use Kambo\Langchain\Indexes\VectorstoreIndexCreator;
use Kambo\Langchain\LLMs\OpenAIChat;


// Go through all posts and create embeddings
function aichwp_create_initial_embeddings() {
  global $wpdb;

  //error_log('aichwp_create_initial_embeddings');

  update_option('aichwp_create_initial_embeddings_running', 1);

  $posts = aichwp_get_posts();

  // Get the total number of posts
  $total_posts = count($posts);

  // Update the progress option
  $progress = [
      'total' => $total_posts,
      'processed' => 0,
      'failed' => 0,
  ];
  update_option('aichwp_embeddings_progress', $progress);
  aichwp_release_semaphore_lock();

  // Loop through each post and schedule an action to create embeddings
  foreach ($posts as $index => $post) {
      $timestamp = time() + ($index * 2); // Schedule each action 2 seconds apart

      // Get all post metadata
      $post_metadata = get_post_meta($post->ID);

      // Filter post content
      $post_content = aichwp_transform_post_content($post->post_content);

      // Split the combined content into chunks
      $content_chunks = aichwp_split_content($post_content, 7000);

      // Get the total number of chunks for the post
      $total_chunks = count($content_chunks);

      //error_log("Post ID: $post->ID, Total Chunks: $total_chunks");

      // Directly delete all embeddings with the matching post_id
      $deleted = $wpdb->delete("{$wpdb->prefix}aichat_post_embeddings", ['post_id' => $post->ID]);

      // Schedule the fromPost() call for each content chunk
      foreach ($content_chunks as $chunk_index => $chunk) {
          $scheduleid = as_schedule_single_action($timestamp, 'aichwp_create_post_embeddings', [$post->ID, $chunk, $chunk_index, $total_chunks]);
          //error_log("Scheduled post ID: $post->ID, Chunk: $chunk_index, Schedule ID: $scheduleid");
      }
  }
}

add_action('aichwp_create_post_embeddings', 'aichwp_create_post_embeddings_callback', 10, 4);

function aichwp_get_posts() {
    // Get all published posts
    $post_types = get_post_types(['public' => true], 'names');
    unset($post_types['attachment']);

    $posts = get_posts([
        'post_type' => $post_types,
        'post_status' => 'publish',
        'posts_per_page' => -1,
    ]);

    // Get wp_template posts separately
    $wp_template_posts = get_posts([
        'post_type' => 'wp_template',
        'post_status' => 'publish',
        'posts_per_page' => -1,
    ]);

    // Merge the two arrays
    $posts = array_merge($posts, $wp_template_posts);

    $posts = array_filter($posts, function ($post) {
        return !empty(trim(aichwp_transform_post_content($post->post_content)));
    });

    return $posts;
}

function aichwp_create_post_embeddings_callback($post_id, $content_chunk, $chunk_index, $total_chunks) {

    // Retrieve the post
    $post = get_post($post_id);

    // Get the current retry count for the post and chunk
    $retry_key = '_aichwp_embeddings_retry_count_' . $post_id . '_' . $chunk_index;
    $retry_count = get_post_meta($post_id, $retry_key, true);
    $retry_count = (int) $retry_count;

    try {
        $indexCreator = new VectorstoreIndexCreator();

        // Create embeddings for the post chunk
        $index = $indexCreator->fromPost(
            $post_id,
            $post->post_type,
            $post->post_status,
            $post->guid,
            $post->post_title,
            $content_chunk,
            ['collection_name' => 'posts']
        );

        if (!$index) {
            throw new Exception("Failed to create embeddings for post ID: $post_id, chunk: $chunk_index");
        }

        // Remove the retry count meta if the embeddings creation is successful
        delete_post_meta($post_id, $retry_key);

        // Acquire the semaphore lock
        while (!aichwp_acquire_semaphore_lock()) {
            sleep(1);
        }

        // Mark the post ID as completed
        $progress = get_option('aichwp_embeddings_progress');

        // Check if all chunks for the post are completed
        if (aichwp_are_all_chunks_completed($post_id, $total_chunks)) {
            $progress['processed']++;
            //error_log("Completed post ID: $post_id  total_chunks: $total_chunks");
        }

        update_option('aichwp_embeddings_progress', $progress);

        // Check if all posts are completed
        if ($progress['processed'] + $progress['failed'] >= $progress['total']) {
            update_option('aichwp_post_embeddings_are_stale', 0);
        }

        aichwp_release_semaphore_lock();
    } catch (Exception $e) {
        // Log the error
        error_log("Error creating embeddings for post ID: $post_id, chunk: $chunk_index. Message: " . $e->getMessage());

        aichwp_release_semaphore_lock();

        // Increment the retry count
        $retry_count++;

        // If the retry count is less than or equal to 5, schedule a retry
        if ($retry_count <= 5) {
            // Update the retry count meta
            update_post_meta($post_id, $retry_key, $retry_count);

            // Schedule a retry for the failed post chunk after a delay (e.g., 15 seconds)
            $retry_timestamp = time() + (1 * 15);
            as_schedule_single_action($retry_timestamp, 'aichwp_create_post_embeddings', [$post_id, $content_chunk, $chunk_index]);
        } else {
            // If the retry count exceeds 5, add the post ID to the failed array
            $progress = get_option('aichwp_embeddings_progress');
            $progress['failed']++;
            update_option('aichwp_embeddings_progress', $progress);
        }
    } catch (Throwable $e) {
        // Log any unexpected errors
        error_log("Unexpected error occurred while creating embeddings for post ID: $post_id, chunk: $chunk_index. Message: " . $e->getMessage());

        aichwp_release_semaphore_lock();

        // Add the post ID to the failed array
        $progress = get_option('aichwp_embeddings_progress');
        $progress['failed']++;
        update_option('aichwp_embeddings_progress', $progress);
    }
}

function aichwp_split_content($content, $chunk_size) {
  $chunks = [];
  $content_length = strlen($content);
  
  if ($content_length === 0) {
      $chunks[] = ' ';
      return $chunks;
  }
  
  $start = 0;
  
  while ($start < $content_length) {
      $chunk = substr($content, $start, $chunk_size);
      $chunks[] = $chunk;
      $start += $chunk_size;
  }
  
  return $chunks;
}

function aichwp_are_all_chunks_completed($post_id, $total_chunks) {
  $completed_chunks = 0;

  for ($i = 0; $i < $total_chunks; $i++) {
      $retry_key = '_aichwp_embeddings_retry_count_' . $post_id . '_' . $i;
      if (!get_post_meta($post_id, $retry_key, true)) {
          $completed_chunks++;
      }
  }

  return $completed_chunks === $total_chunks;
}

// Acquire the semaphore lock
function aichwp_acquire_semaphore_lock() {
  $semaphore = get_option('aichwp_embeddings_progress_semaphore', false);

  if ($semaphore) {
      return false; // Semaphore is already locked
  }

  update_option('aichwp_embeddings_progress_semaphore', true);
  return true; // Semaphore lock acquired
}

// Release the semaphore lock
function aichwp_release_semaphore_lock() {
  update_option('aichwp_embeddings_progress_semaphore', false);
}

// Unschedule the initial embeddings creation
function aichwp_unschedule_initial_embeddings() {
  // Clear any existing scheduled actions
  as_unschedule_all_actions('aichwp_create_post_embeddings');
  
  update_option('aichwp_create_initial_embeddings_running', 0);
}

// AJAX endpoint to get the indexing progress
function aichwp_get_indexing_progress() {
  $progress = get_option('aichwp_embeddings_progress');

  if (!is_array($progress)) {
    wp_send_json_success(null);
    return;
  }

  if ($progress['processed'] + $progress['failed'] >= $progress['total']) {
      wp_send_json_success(null);
  } else {
      wp_send_json_success($progress);
  }
}
add_action('wp_ajax_aichwp_get_indexing_progress', 'aichwp_get_indexing_progress');


// Create the initial tables
function aichwp_create_initial_tables() {
  global $wpdb;

  $wpdb->query("
      CREATE TABLE IF NOT EXISTS {$wpdb->prefix}aichat_post_collection (
          `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          `name` varchar(255) NOT NULL
      )
  ");

  $wpdb->query("
      CREATE TABLE IF NOT EXISTS {$wpdb->prefix}aichat_post_embeddings (
          `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          `uuid` varchar(255),
          `collection_id` bigint(20) UNSIGNED,
          `post_id` bigint(20) UNSIGNED,
          `post_type` varchar(255),
          `document` text NOT NULL,
          `metadata` text NOT NULL,
          `vector` text NOT NULL,
          `is_active` tinyint(1) DEFAULT 1
      )
  ");

  $wpdb->query("
      CREATE TABLE IF NOT EXISTS {$wpdb->prefix}aichat_user_messages (
          `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          `user_hash` varchar(255) NOT NULL,
          `messages` int(11) NOT NULL DEFAULT 0,
          `latest_hour` int(11) NOT NULL
      )
  ");
}

// On post save, unpublish, or delete, schedule an action to update the embeddings
function aichwp_save_post($post_id, $post, $update) {
  global $wpdb;
  
  // Check for autosave or revisions
  if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
  
  $post_types = get_post_types(['public' => true], 'names');
  $post_types[] = 'wp_template';
  
  if (!in_array($post->post_type, $post_types)) {
      return;
  }
  
  if ($post->post_status === 'publish') {
      
      // Filter post content
      $post_content = aichwp_transform_post_content($post->post_content);
      
      // Split the combined content into chunks
      $content_chunks = aichwp_split_content($post_content, 7000);
      
      // Directly delete all embeddings with the matching post_id
      $deleted = $wpdb->delete("{$wpdb->prefix}aichat_post_embeddings", ['post_id' => $post_id]);
      
      // Schedule the fromPost() call for each content chunk
      foreach ($content_chunks as $index => $chunk) {
          as_enqueue_async_action('aichwp_update_post_embeddings', [$post_id, $post->post_type, $post->post_status, $post->guid, $post->post_title, $chunk, $index]);
      }
  } else {
      // Set is_active to 0 for unpublished or trashed posts
      global $wpdb;
      $wpdb->update(
          "{$wpdb->prefix}aichat_post_embeddings",
          ['is_active' => 0],
          ['post_id' => $post_id]
      );
  }
}
add_action('save_post', 'aichwp_save_post', 10, 3);

function aichwp_update_post_embeddings($post_id, $post_type, $post_status, $post_guid, $post_title, $content_chunk, $chunk_index) {
  try {
      $index = (new VectorstoreIndexCreator())->fromPost($post_id, $post_type, $post_status, $post_guid, $post_title, $content_chunk, ['collection_name' => 'posts']);
      //error_log("Updated chunk $chunk_index for post $post_id, $post_title");
  } catch (Exception $e) {
      error_log("Error updating embeddings for post $post_id, chunk $chunk_index: " . $e->getMessage());
  }
}
add_action('aichwp_update_post_embeddings', 'aichwp_update_post_embeddings', 10, 7);

function aichwp_delete_post_embeddings($post_id) {
  global $wpdb;
  $wpdb->delete(
      "{$wpdb->prefix}aichat_post_embeddings",
      ['post_id' => $post_id]
  );
}
add_action('delete_post', 'aichwp_delete_post_embeddings');


// Plugin activation hook
function aichwp_plugin_activation() {
    aichwp_create_initial_tables();

    update_option('aichwp_post_embeddings_are_stale', 1);
    //error_log('activate - aichwp_post_embeddings_are_stale');

    $options = get_option('aichwp_settings', array());

    // Check if a valid OpenAI API key exists
    if (!isset($options['openai_api_key']) || empty($options['openai_api_key'])) {
        //error_log('No existing openai api key, halt.');
    }
    else {
        try {
            // Test the api key
            $openAi = new OpenAIChat(['temperature' => 0.8]);
            $response = $openAi->generateResultString(['This is a test. Reply <true> and nothing else.']);
            
            // Trigger the embeddings creation
            aichwp_create_initial_embeddings();
        } catch (Exception $e) {
            //error_log('No valid openai api key, halt.');
        }
    }
}

// Transform post content
function aichwp_transform_post_content($postContent) {
  return preg_replace("/\n\s*\n/", "\n", wp_strip_all_tags($postContent));
}

// Plugin deactivation hook
function aichwp_plugin_deactivation() {
    aichwp_unschedule_initial_embeddings();
}
