<?php

// VectorStores/SimpleStupidVectorStore/SimpleStupidVectorStore.php

namespace Kambo\Langchain\VectorStores\SimpleStupidVectorStore;

use const ARRAY_A;

class SimpleStupidVectorStore
{
    private $options;
    private const TABLES = [
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

    public function __construct(array $options = [])
    {
        global $wpdb;
        $this->options = $options;
        $this->createTables();
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

    protected function createTables()
    {
        global $wpdb;
        foreach (self::TABLES as $tableName => $columns) {
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
}
