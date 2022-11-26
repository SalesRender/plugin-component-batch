<?php
/**
 * Created for plugin-component-batch
 * Date: 02.03.2020
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace Leadvertex\Plugin\Components\Batch;


use Leadvertex\Plugin\Components\Access\Token\GraphqlInputToken;
use Leadvertex\Plugin\Components\Access\Token\InputTokenInterface;
use Leadvertex\Plugin\Components\ApiClient\ApiClient;
use Leadvertex\Plugin\Components\ApiClient\ApiFilterSortPaginate;
use Leadvertex\Plugin\Components\Db\Model;
use Leadvertex\Plugin\Components\Form\FormData;
use RuntimeException;

final class Batch extends Model
{

    protected int $createdAt;

    protected InputTokenInterface $token;

    protected ApiFilterSortPaginate $fsp;

    protected string $lang;

    protected array $arguments = [];

    /** @var FormData[]  */
    protected array $options = [];

    public function __construct(InputTokenInterface $token, ApiFilterSortPaginate $fsp, string $lang, array $arguments = [])
    {
        $this->createdAt = time();
        $this->id = $token->getId();
        $this->token = $token;
        $this->fsp = $fsp;
        $this->lang = $lang;
        $this->arguments = $arguments;
        $this->options = [];
    }

    public function getToken(): InputTokenInterface
    {
        return $this->token;
    }

    public function getFsp(): ApiFilterSortPaginate
    {
        return $this->fsp;
    }

    public function getLang(): string
    {
        return $this->lang;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function getOptions(int $number): ?FormData
    {
        return $this->options[$number] ?? null;
    }

    public function setOptions(int $number, FormData $data)
    {
        $this->options[$number]  = $data;
    }

    public function countOptions(): int
    {
        return count($this->options);
    }

    public function getApiClient(): ApiClient
    {
        return new ApiClient(
            $this->token->getBackendUri() . "companies/{$this->token->getCompanyId()}/CRM",
            (string) $this->token->getOutputToken()
        );
    }

    public static function find(): ?Model
    {
        $token = GraphqlInputToken::getInstance();
        if (is_null($token)) {
            throw new RuntimeException('Batch can not be found without GraphqlInputToken::getInstance()');
        }
        return self::findById($token->getId());
    }

    public static function schema(): array
    {
        return [
            'token' => ['TEXT', 'NOT NULL'],
            'fsp' => ['TEXT', 'NOT NULL'],
            'lang' => ['CHAR(5)', 'NOT NULL'],
            'arguments' => ['TEXT'],
            'options' => ['TEXT'],
        ];
    }

    protected static function beforeWrite(array $data): array
    {
        $data['token'] = serialize($data['token']);
        $data['fsp'] = serialize($data['fsp']);
        $data['arguments'] = serialize($data['arguments']);
        $data['options'] = serialize($data['options']);
        return $data;
    }

    protected static function afterRead(array $data): array
    {
        $data['token'] = unserialize($data['token']);
        $data['fsp'] = unserialize($data['fsp']);
        $data['arguments'] = unserialize($data['arguments']);
        $data['options'] = unserialize($data['options']);
        return $data;
    }

}