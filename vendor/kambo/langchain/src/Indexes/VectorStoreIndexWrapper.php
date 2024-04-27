<?php
// Indexes/VectorStoreIndexWrapper.php

namespace Kambo\Langchain\Indexes;

use Kambo\Langchain\LLMs\BaseLLM;
use Kambo\Langchain\VectorStores\VectorStore;
use Kambo\Langchain\LLMs\OpenAI;
use Kambo\Langchain\Chains\VectorDbQa\VectorDBQA;
use function array_merge;

/**
 * Wrapper around a vectorstore for easy access.
 */
class VectorStoreIndexWrapper
{
    public function __construct(public VectorStore $vectorStore)
    {
    }

    /**
     * Query the vectorstore.
     *
     * @param string $question
     * @param BaseLLM|null $llm
     * @param array $additionalParams
     *
     * @return string
     */
    public function query(string $question, ?BaseLLM $llm = null, array $additionalParams = []): string
    {
        $llm = $llm ?? new OpenAI(['temperature' => 0]);
        $chain = VectorDBQA::fromChainType(
            $llm,
            'stuff',
            null,
            array_merge(['vectorstore' => $this->vectorStore], $additionalParams)
        );
        return $chain(['query' => $question])[$chain->outputKey];
    }

    /**
     * Query the vectorstore and get back the answer and source documents.
     *
     * @param string $question
     * @param BaseLLM|null $llm
     * @param array $additionalParams
     *
     * @return array
     */
    public function queryWithSources(string $question, ?BaseLLM $llm = null, array $additionalParams = []): array
    {
        $llm = $llm ?? new OpenAI(['temperature' => 0]);
        $chain = VectorDBQA::fromChainType(
            $llm,
            'stuff',
            null,
            array_merge(['vectorstore' => $this->vectorStore, 'return_source_documents' => true], $additionalParams)
        );

        //error_log(print_r($chain));

        $result = $chain(['query' => $question]);

        return [
            'query' => $question,
            'result' => $result[$chain->outputKey],
            'source_documents' => $result['source_documents'] ?? [],
        ];
    }
}