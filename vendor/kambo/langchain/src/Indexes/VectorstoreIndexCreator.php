<?php
// Indexes/VectorstoreIndexCreator.php

namespace Kambo\Langchain\Indexes;

use Kambo\Langchain\TextSplitter\RecursiveCharacterTextSplitter;
use Kambo\Langchain\TextSplitter\TextSplitter;
use Kambo\Langchain\VectorStores\SimpleStupidVectorStore;
use Kambo\Langchain\Embeddings\Embeddings;
use Kambo\Langchain\Embeddings\OpenAIEmbeddings;
use Kambo\Langchain\Docstore\Document;

use function array_merge;

/**
 * Logic for creating indexes.
 */
class VectorstoreIndexCreator
{
    public string $vectorstoreCls = SimpleStupidVectorStore::class;
    public TextSplitter $textSplitter;
    public Embeddings $embedding;

    /**
     * @param string|null       $vectorstoreCls
     * @param Embeddings|null   $embedding
     * @param TextSplitter|null $textSplitter
     */
    public function __construct(
        ?string $vectorstoreCls = null,
        ?Embeddings $embedding = null,
        ?TextSplitter $textSplitter = null
    ) {
        $this->vectorstoreCls = $vectorstoreCls ?? SimpleStupidVectorStore::class;
        $this->textSplitter   = $textSplitter ?? new RecursiveCharacterTextSplitter(
            [
                'chunk_size' => 350,
                'chunk_overlap' => 25
            ]
        );
        $this->embedding = $embedding ?? new OpenAIEmbeddings();
    }


    /**
     * Gets an existing vectorstore from the database given a collection name
     *
     * @param string $collectionName
     *
     * @return VectorStoreIndexWrapper
     */
    public function fromExisting(string $collectionName): VectorStoreIndexWrapper
    {
        global $wpdb;

        // Fetch the collection by name
        $query = $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}aichat_post_collection WHERE name = %s",
            $collectionName
        );
        $result = $wpdb->get_row($query, ARRAY_A);

        if (empty($result)) {
            throw new Exception("No collection found with the name '{$collectionName}'.");
        }

        $collectionId = $result['id'];

        // Create an instance of SimpleStupidVectorStore
        $vectorStore = new SimpleStupidVectorStore($this->embedding);

        // Set the collection in the vector store using the constructor
        $options = ['collection_name' => $collectionName];
        $vectorStore = new SimpleStupidVectorStore($this->embedding, null, $options);

        // Return a VectorStoreIndexWrapper for the collection
        return new VectorStoreIndexWrapper($vectorStore);
    }
    
    



    /**
     * Create a vectorstore index from post.
     *
     * @param int $post_id
     * @param string $post_title
     * @param string $post_content
     *
     * @return VectorStoreIndexWrapper
     */
    public function fromPost(int $post_id, string $post_type, string $post_status, string $post_guid, string $post_title, string $post_content, array $additionalParams = []): VectorStoreIndexWrapper
    {
        $docs = [];

        $post_content = strip_tags($post_content);
        
        $docs[] = new Document(pageContent:$post_content, metadata: ['post_title' => $post_title, 'post_type' => $post_type, 'post_status' => $post_status, 'post_guid' => $post_guid, 'post_id' => $post_id]);

        $subDocs = $this->textSplitter->splitDocuments($docs);
        
        foreach($subDocs as $doc) {
          $doc->pageContent = "PostTitle: $post_title\n" . $doc->pageContent; 
        }

        $vectorstore = $this->vectorstoreCls::fromDocuments(
            $subDocs,
            $this->embedding,
            $additionalParams
        );

        return new VectorStoreIndexWrapper($vectorstore);
    }

    /**
     * Create a vectorstore index from loaders.
     *
     * @param array $loaders
     * @param array $additionalParams
     *
     * @return VectorStoreIndexWrapper
     */
    public function fromLoaders(array $loaders, array $additionalParams = []): VectorStoreIndexWrapper
    {
        $docs = [];
        foreach ($loaders as $loader) {
            $docs = array_merge($docs, $loader->load());
        }

        $subDocs    = $this->textSplitter->splitDocuments($docs);
        $vectorstore = $this->vectorstoreCls::fromDocuments(
            $subDocs,
            $this->embedding,
            $additionalParams
        );

        return new VectorStoreIndexWrapper($vectorstore);
    }
}
