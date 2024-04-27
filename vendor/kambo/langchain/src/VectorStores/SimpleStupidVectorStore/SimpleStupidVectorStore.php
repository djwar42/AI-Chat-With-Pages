<?php

// VectorStores/SimpleStupidVectorStore/SimpleStupidVectorStore.php

namespace Kambo\Langchain\VectorStores\SimpleStupidVectorStore;

use const ARRAY_A;

class SimpleStupidVectorStore
{
    private $options;

    public function __construct(array $options = [])
    {
        global $wpdb;
        $this->options = $options;
    }

    public function getOrCreateCollection(string $name, array $options)
    {
        global $wpdb;
        $stmt = $wpdb->prepare("SELECT id FROM {$wpdb->prefix}aichat_post_collection WHERE name = %s", $name);
        $resultArray = $wpdb->get_row($stmt, ARRAY_A);

        if (empty($resultArray)) {
            $wpdb->insert("{$wpdb->prefix}aichat_post_collection", ['name' => $name]);
            $collectionId = $wpdb->insert_id;
        } else {
            $collectionId = $resultArray['id'];
        }

        return new Collection($collectionId, $options);
    }
}
