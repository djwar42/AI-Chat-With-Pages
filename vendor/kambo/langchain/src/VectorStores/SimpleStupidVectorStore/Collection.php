<?php

// SimpleStupidVectorStore/Collection.php

namespace Kambo\Langchain\VectorStores\SimpleStupidVectorStore;

global $wpdb;

use function array_map;
use function json_encode;
use function json_decode;
use function arsort;
use function array_slice;
use function sqrt;

class Collection
{
    private $collectionId;
    private $options;

    public function __construct(
        int $collectionId,
        array $options = []
    ) {
        $this->collectionId = $collectionId;
        $this->options = $options;
    }

    public function add(
        array $metadatas,
        array $embeddings,
        iterable $texts,
        array $uuids
    ): array {
        global $wpdb;
        $combined = array_map(null, $metadatas, $texts, $uuids, $embeddings);
        $embeddingsIds = [];

        //error_log(print_r($combined[0], true));

        foreach ($combined as $row) {
            // Serialize the entire vector as JSON
            $serializedVector = json_encode($row[3]);
                    
            if(isset($row[0]['post_id'])) {
                $post_id = $row[0]['post_id'];
            }
            else {
                $post_id = -1;
            }

            if(isset($row[0]['post_type'])) {
                $post_type = $row[0]['post_type'];
            }
            else {
                $post_type = '';
            }

            if(isset($row[0]['post_status'])) {
                if($post_status = $row[0]['post_status'] == 'publish') {
                    $post_status = 1;
                }
                else {
                    $post_status = 0;
                }
            }
            else {
                $post_status = 0;
            }

            // Insert the metadata, document information, and serialized vector into the 'embeddings' table
            $wpdb->insert("{$wpdb->prefix}aichat_post_embeddings", [
                'uuid' => $row[2],
                'collection_id' => $this->collectionId,
                'post_type' => $post_type,
                'post_id' => $post_id,
                'document' => $row[1],
                'metadata' => json_encode($row[0]),
                'vector' => $serializedVector,
                'is_active' => $post_status
            ]);
            $embeddingsId = $wpdb->insert_id;
            $embeddingsIds[] = $embeddingsId;
        }
        return $embeddingsIds;
    }


    public function similaritySearchWithScore(array $queryEmbedding, int $k, array $postTypes = []) {
        global $wpdb;
    
        // Build the SQL query based on the provided post types
        $sql = "SELECT id, uuid, document, metadata, vector 
                FROM {$wpdb->prefix}aichat_post_embeddings 
                WHERE collection_id = %d AND is_active = 1";
    
        $params = [$this->collectionId];
    
        if (!empty($postTypes)) {
            $placeholders = implode(',', array_fill(0, count($postTypes), '%s'));
            $sql .= " AND post_type IN ($placeholders)";
            $params = array_merge($params, $postTypes);
        }
    
        $sql .= " ORDER BY id ASC";
    
        $results = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    
        $embeddingsData = [];
        $scores = [];
    
        foreach ($results as $row) {
            // Decode the JSON vector into an array
            $vector = json_decode($row['vector'], true);
    
            // Store embeddings data
            $embeddingsData[$row['id']] = [
                'uuid' => $row['uuid'],
                'document' => $row['document'],
                'metadata' => json_decode($row['metadata'], true),
            ];
    
            // Calculate the cosine similarity
            $scores[$row['id']] = $this->cosineSimilarity($queryEmbedding, $vector);
        }
    
        arsort($scores);
    
        $result = [];
        foreach (array_slice($scores, 0, $k, true) as $id => $score) {
            $result[] = [
                $embeddingsData[$id],
                'score' => $score,
            ];
        }
    
        return $result;
    }

    private function cosineSimilarity(array $queryEmbedding, array $embedding)
    {
        $dotProduct = 0;
        $queryEmbeddingLength = 0;
        $embeddingLength = 0;

        foreach ($queryEmbedding as $key => $value) {
            if (isset($embedding[$key])) { // Check if the key exists in the embedding to prevent errors
                $dotProduct += $value * $embedding[$key];
                $queryEmbeddingLength += $value * $value;
                $embeddingLength += $embedding[$key] * $embedding[$key];
            }
        }

        if ($queryEmbeddingLength == 0 || $embeddingLength == 0) {
            // Prevent division by zero
            return 0;
        }

        return $dotProduct / (sqrt($queryEmbeddingLength) * sqrt($embeddingLength));
    }

}

