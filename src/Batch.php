<?php
/**
 * Created for plugin-component-batch
 * Date: 02.03.2020
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace Leadvertex\Plugin\Components\Batch;


use Leadvertex\Plugin\Components\ApiClient\ApiClient;
use Leadvertex\Plugin\Components\ApiClient\ApiFilterSortPaginate;
use Leadvertex\Plugin\Components\Db\Model;
use Leadvertex\Plugin\Components\Form\FormData;
use Leadvertex\Plugin\Components\Token\InputTokenInterface;
use Leadvertex\Plugin\Components\Token\TokenException;

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

    public function __construct(InputTokenInterface $token, ApiFilterSortPaginate $fsp, string $lang)
    {
        parent::__construct($token->getId());

        $this->token = $token;
        $this->fsp = $fsp;
        $this->lang = $lang;

        $this->options = [];

        if ($token->getCompanyId() != $this->getCompanyId()) {
            throw new TokenException('Mismatch token company ID and current company ID');
        }
    }

    public function getToken(): InputTokenInterface
    {
        return $this->token;
    }

    public function getLang(): string
    {
        return $this->lang;
    }

    public function getFsp(): ApiFilterSortPaginate
    {
        return $this->fsp;
    }

    public function getApiClient(): ApiClient
    {
        return new ApiClient(
            $this->token->getBackendUri() . 'companies/stark-industries/CRM',
            (string) $this->token->getOutputToken()
        );
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

}