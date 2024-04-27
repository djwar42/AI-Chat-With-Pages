<?php
// ai-chat-with-pages-indexing.php
require_once AICHWP_PLUGIN_DIR . '/vendor/action-scheduler/action-scheduler.php';
require_once AICHWP_PLUGIN_DIR .'/vendor/autoload.php';

use Kambo\Langchain\Indexes\VectorstoreIndexCreator;

/**
 * Go through all posts and create embeddings
*/
function aichwp_create_initial_embeddings() {
  global $wpdb;

  // Get all published posts
  $post_types = get_post_types(['public' => true], 'names');
  $post_types[] = 'wp_template';
  unset($post_types['attachment']);
  $posts = get_posts([
      'post_type' => $post_types,
      'post_status' => 'publish',
      'posts_per_page' => -1,
  ]);

  $posts = array_filter($posts, function ($post) {
      return !empty(trim(preg_replace("/\n\s*\n/", "\n", strip_tags($post->post_content))));
  });

  // Get the total number of posts
  $total_posts = count($posts);
  //error_log("Total: $total_posts");

  // Create an array of post IDs and mark them as not completed
  $post_ids = array_fill_keys(wp_list_pluck($posts, 'ID'), false);

  // Update the progress option
  $progress = [
      'total' => $total_posts,
      'processed' => 0,
      'failed' => [],
      'post_ids' => $post_ids,
  ];
  update_option('aichwp_embeddings_progress', $progress);
  aichwp_release_semaphore_lock();

  // Loop through each post and schedule an action to create embeddings
  foreach ($posts as $index => $post) {
      $timestamp = time() + ($index * 2); // Schedule each action 2 seconds apart

      // Get all post metadata
      $post_metadata = get_post_meta($post->ID);

      // Filter post content
      $post_content = preg_replace("/\n\s*\n/", "\n", strip_tags($post->post_content));

      // Split the combined content into chunks
      $content_chunks = aichwp_split_content($post_content, 7000);

      //error_log(print_r($content_chunks, true));

      // Get the total number of chunks for the post
      $total_chunks = count($content_chunks);

      // Directly delete all embeddings with the matching post_id
      $deleted = $wpdb->delete("{$wpdb->prefix}aichat_post_embeddings", ['post_id' => $post->ID]);

      // Schedule the fromPost() call for each content chunk
      foreach ($content_chunks as $chunk_index => $chunk) {
          $scheduleid = as_schedule_single_action($timestamp, 'aichwp_create_post_embeddings', [$post->ID, $chunk, $chunk_index, $total_chunks]);
          //error_log($scheduleid . " scheduling " . $timestamp . " " . $post->ID . " chunk " . ($chunk_index));
      }
  }
}

add_action('aichwp_create_post_embeddings', 'aichwp_create_post_embeddings_callback', 10, 4);

function aichwp_create_post_embeddings_callback($post_id, $content_chunk, $chunk_index, $total_chunks) {
  // Retrieve the post
  $post = get_post($post_id);

  // Get the current retry count for the post and chunk
  $retry_key = '_aichwp_embeddings_retry_count_' . $post_id . '_' . $chunk_index;
  $retry_count = get_post_meta($post_id, $retry_key, true);
  $retry_count = (int) $retry_count;

  try {
      // Create an instance of VectorstoreIndexCreator
      $indexCreator = new VectorstoreIndexCreator();

      // Create embeddings for the post chunk
      $index = $indexCreator->fromPost(
          $post_id,
          $post->post_type,
          $post->guid,
          $post->post_title,
          $content_chunk,
          ['collection_name' => 'posts']
      );

      if (!$index) {
          throw new Exception("Failed to create embeddings for post ID: $post_id, chunk: $chunk_index");
      }

      //error_log("Embeddings created successfully for post ID: $post_id, chunk: $chunk_index");

      // Remove the retry count meta if the embeddings creation is successful
      delete_post_meta($post_id, $retry_key);

      // Acquire the semaphore lock
      while (!aichwp_acquire_semaphore_lock()) {
          sleep(1);
          //error_log('waiting for semaphore lock');
      }

      // Mark the post ID as completed
      $progress = get_option('aichwp_embeddings_progress', [
          'total'     => 0,
          'processed' => 0,
          'failed'    => [],
          'post_ids'  => [],
      ]);

      // Check if all chunks for the post are completed
      if (aichwp_are_all_chunks_completed($post_id, $total_chunks)) {
          $progress['post_ids'][$post_id] = true;
          $progress['processed']++; 
      }

      update_option('aichwp_embeddings_progress', $progress);

      //error_log("Count:" . count(array_filter($progress['post_ids'])));

      // Check if all posts are completed
      if ($progress['processed'] === $progress['total']) {
          delete_option('aichwp_embeddings_progress');
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
          $progress = get_option('aichwp_embeddings_progress', [
              'total'     => 0,
              'processed' => 0,
              'failed'    => [],
              'post_ids'  => [],
          ]);
          $progress['failed'][] = $post_id;
          update_option('aichwp_embeddings_progress', $progress);
      }
  } catch (Throwable $e) {
      // Log any unexpected errors
      error_log("Unexpected error occurred while creating embeddings for post ID: $post_id, chunk: $chunk_index. Message: " . $e->getMessage());

      aichwp_release_semaphore_lock();

      // Add the post ID to the failed array
      $progress = get_option('aichwp_embeddings_progress', [
          'total'     => 0,
          'processed' => 0,
          'failed'    => [],
          'post_ids'  => [],
      ]);
      $progress['failed'][] = $post_id;
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

function aichwp_schedule_initial_embeddings() {
  // Clear any existing scheduled actions for creating initial embeddings
  as_unschedule_all_actions('aichwp_create_initial_embeddings');

  // Delete the progress option
  delete_option('aichwp_embeddings_progress');

  // Run the initial embeddings creation
  aichwp_create_initial_embeddings();
}

function aichwp_clear_embeddings_progress() {
  // Clear any existing scheduled actions for creating initial embeddings
  as_unschedule_all_actions('aichwp_create_initial_embeddings');

  // Delete the progress option
  delete_option('aichwp_embeddings_progress');
}

function aichwp_manual_indexing_callback() {
    aichwp_clear_embeddings_progress();
    aichwp_create_initial_embeddings();

    wp_send_json_success();
}
add_action('wp_ajax_aichwp_manual_indexing', 'aichwp_manual_indexing_callback');

add_action('wp_ajax_aichwp_get_indexing_progress', 'aichwp_get_indexing_progress_callback');

function aichwp_get_indexing_progress_callback() {
  $progress = get_option('aichwp_embeddings_progress');

  if ($progress === false) {
      wp_send_json_success(null);
  } else {
      wp_send_json_success($progress);
  }
}

/**
 * Create tables
*/
function aichwp_create_initial_tables()
{
    global $wpdb;

    $tables = [
        'aichat_post_collection' => [
            'id' => 'bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'name' => 'varchar(255) NOT NULL',
        ],
        'aichat_post_embeddings' => [
            'id' => 'bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'uuid' => 'varchar(255)',
            'collection_id' => 'bigint(20) UNSIGNED',
            'post_id' => 'bigint(20) UNSIGNED',
            'post_type' => 'varchar(255)',
            'document' => 'text NOT NULL',
            'metadata' => 'text NOT NULL',
            'vector' => 'text NOT NULL',
            'is_active' => 'tinyint(1) DEFAULT 1'
        ],
        'aichat_user_messages' => [
            'id' => 'bigint(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'user_hash' => 'varchar(255) NOT NULL',
            'messages' => 'int(11) NOT NULL DEFAULT 0',
            'latest_hour' => 'int(11) NOT NULL'
        ]
    ];

    foreach ($tables as $tableName => $columns) {
        $columnDefinitions = [];
        foreach ($columns as $columnName => $columnType) {
            $columnDefinitions[] = "{$columnName} {$columnType}";
        }

        $columnDefinitionsSql = implode(', ', $columnDefinitions);
        $tableName = $wpdb->prefix . $tableName;
        $sql = "CREATE TABLE IF NOT EXISTS `{$tableName}` ({$columnDefinitionsSql})";
        $wpdb->query($sql);
    }
}

/**
 * On post save, unpublish, or delete, schedule an action to update the embeddings
*/
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
      $post_content = preg_replace("/\n\s*\n/", "\n", strip_tags($post->post_content));
      
      // Split the combined content into chunks
      $content_chunks = aichwp_split_content($post_content, 7000);
      
      // Directly delete all embeddings with the matching post_id
      $deleted = $wpdb->delete("{$wpdb->prefix}aichat_post_embeddings", ['post_id' => $post_id]);
      
      // Schedule the fromPost() call for each content chunk
      foreach ($content_chunks as $index => $chunk) {
          as_enqueue_async_action('aichwp_update_post_embeddings', [$post_id, $post->post_type, $post->guid, $post->post_title, $chunk, $index]);
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

function aichwp_update_post_embeddings($post_id, $post_type, $post_guid, $post_title, $content_chunk, $chunk_index) {
  try {
      $index = (new VectorstoreIndexCreator())->fromPost($post_id, $post_type, $post_guid, $post_title, $content_chunk, ['collection_name' => 'posts']);
      error_log("Updated chunk $chunk_index for post $post_id, $post_title");
  } catch (Exception $e) {
      error_log("Error updating embeddings for post $post_id, chunk $chunk_index: " . $e->getMessage());
  }
}
add_action('aichwp_update_post_embeddings', 'aichwp_update_post_embeddings', 10, 6);

function aichwp_delete_post_embeddings($post_id) {
  global $wpdb;
  $wpdb->delete(
      "{$wpdb->prefix}aichat_post_embeddings",
      ['post_id' => $post_id]
  );
}
add_action('delete_post', 'aichwp_delete_post_embeddings');