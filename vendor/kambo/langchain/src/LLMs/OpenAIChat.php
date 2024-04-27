<?php
namespace Kambo\Langchain\LLMs;

use Kambo\Langchain\Callbacks\CallbackManager;
use OpenAI\Client;
use OpenAI\OpenAI;
use Kambo\Langchain\Exceptions\IllegalState;
use STS\Backoff\Backoff;

use function array_merge;
use function count;
use function sprintf;
use function var_export;
use function get_option;

final class OpenAIChat extends BaseLLM
{
    private Client $client;
    private string $modelName;
    private array $modelAdditionalParams = [];
    private int $maxRetries = 6;
    private array $prefixMessages = [];

    private ?string $openaiApiKey;

    public function __construct(
        array $config = [],
        ?Client $client = null,
        ?CallbackManager $callbackManager = null
    ) {
        parent::__construct($config, $callbackManager);

        $aichwp_settings = get_option('aichwp_settings');
        $this->openaiApiKey = $aichwp_settings['openai_api_key'] ?? null;

        if ($client === null) {
            $client = \OpenAI::client($this->openaiApiKey);
        }

        $this->client = $client;
    }

    public function generateResult(array $prompts, array $stop = null): LLMResult
    {
        [$messages, $params] = $this->getChatParams($prompts, $stop);

        $fullResponse = $this->completionWithRetry($this->client, $messages, ...$params);

        //error_log(print_r($messages, true));

        $generations = [
            [
                new Generation(
                    $fullResponse['choices'][0]['message']['content'],
                )
            ]
        ];

        error_log(print_r(new LLMResult($generations,['token_usage' => $fullResponse['usage']],), true));

        return new LLMResult(
            $generations,
            ['token_usage' => $fullResponse['usage']],
        );
    }

    public function generateResultString(array $prompts, array $stop = null): string
    {
        [$messages, $params] = $this->getChatParams($prompts, $stop);

        $fullResponse = $this->completionWithRetry($this->client, $messages, ...$params);

        return $fullResponse['choices'][0]['message']['content'];
    }

    private function completionWithRetry($client, $params, ...$kwargs)
    {
        $params = array_merge($kwargs, ['messages' => [$params]]);
        $backoff = new Backoff($this->maxRetries, 'exponential', 3000, true);
        $result = $backoff->run(function () use ($client, $params) {
            return $client->chat()->create($params);
        });

        return $result->toArray();
    }

    public function getIdentifyingParams(): array
    {
        return [
            'model_name' => $this->modelName,
            'model_kwargs' => $this->defaultParams(),
        ];
    }

    private function defaultParams(): array
    {
        return $this->modelAdditionalParams;
    }

    private function getChatParams(array $prompts, ?array $stop = null): array
    {
        if (count($prompts) > 1) {
            throw new IllegalState(
                sprintf(
                    'OpenAIChat currently only supports single prompt, got %s',
                    var_export($prompts, true)
                )
            );
        }

        $messages = array_merge(
            $this->prefixMessages,
            ['role' => 'user', 'content' => $prompts[0]]
        );

        // Get the OpenAI chat model from WordPress options or default to 'gpt-3.5-turbo'
        $this->modelName = get_option('aichwp_settings')['openai_chat_model'] ?? 'gpt-3.5-turbo';

        $params = array_merge(['model' => $this->modelName], $this->defaultParams());

        if ($stop !== null) {
            if (isset($params['stop'])) {
                throw new IllegalState('`stop` found in both the input and default params.');
            }

            $params['stop'] = $stop;
        }

        if (isset($params['max_tokens']) && $params['max_tokens'] === -1) {
            unset($params['max_tokens']);
        }

        return [$messages, $params];
    }

    public function llmType(): string
    {
        return 'openai-chat';
    }

    public function toArray(): array
    {
        return $this->getIdentifyingParams();
    }
}