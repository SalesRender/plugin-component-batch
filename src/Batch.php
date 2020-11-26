<?php
/**
 * Created for plugin-component-batch
 * Date: 02.03.2020
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace Leadvertex\Plugin\Components\Batch;


use Leadvertex\Plugin\Components\Access\Token\InputTokenInterface;
use Leadvertex\Plugin\Components\ApiClient\ApiClient;
use Leadvertex\Plugin\Components\ApiClient\ApiFilterSortPaginate;
use Leadvertex\Plugin\Components\Db\Model;
use Leadvertex\Plugin\Components\Form\FormData;

/**
 * Class Session
 * @package Leadvertex\Plugin\Core\Macros\Models
 *
 * @property InputTokenInterface $token
 * @property string $lang
 * @property ApiFilterSortPaginate $fsp
 * @property array options
 */
class Batch extends Model
{

    private int $createdAt;

    private InputTokenInterface $token;

    private ApiFilterSortPaginate $fsp;

    private string $lang;

    /** @var FormData[]  */
    private array $options;

    public function __construct(InputTokenInterface $token, ApiFilterSortPaginate $fsp, string $lang)
    {
        $this->createdAt = time();
        $this->id = $token->getId();
        $this->token = $token;
        $this->fsp = $fsp;
        $this->lang = $lang;
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

    public function getOptions(int $number): FormData
    {
        return $this->options[$number] ?? new FormData([]);
    }

    public function setOptions(int $number, FormData $data)
    {
        $options = $this->options;
        $options[$number] = $data;
        $this->options = $options;
    }

    public function getApiClient(): ApiClient
    {
        return new ApiClient(
            $this->token->getBackendUri() . 'companies/stark-industries/CRM',
            (string) $this->token->getOutputToken()
        );
    }

    protected static function beforeWrite(array $data): array
    {
        $data['token'] = serialize($data['token']);
        $data['fsp'] = serialize($data['fsp']);
        $data['options'] = serialize($data['options']);
        return $data;
    }

    protected static function afterRead(array $data): array
    {
        $data['token'] = unserialize($data['token']);
        $data['fsp'] = unserialize($data['fsp']);
        $data['options'] = unserialize($data['options']);
        return $data;
    }

    public static function schema(): array
    {
        return [
            'token' => ['TEXT', 'NOT NULL'],
            'fsp' => ['TEXT', 'NOT NULL'],
            'lang' => ['CHAR(5)', 'NOT NULL'],
            'options' => ['TEXT'],
        ];
    }
}