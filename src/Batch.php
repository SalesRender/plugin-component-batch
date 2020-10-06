<?php
/**
 * Created for plugin-core
 * Date: 02.03.2020
 * @author Timur Kasumov (XAKEPEHOK)
 */

namespace Leadvertex\Plugin\Components\Batch;


use Leadvertex\Plugin\Components\ApiClient\ApiClient;
use Leadvertex\Plugin\Components\ApiClient\ApiFilterSortPaginate;
use Leadvertex\Plugin\Components\Db\Model;
use Leadvertex\Plugin\Components\Form\FormData;
use Leadvertex\Plugin\Components\Process\Process;
use Leadvertex\Plugin\Components\Batch\Exceptions\BatchException;
use Leadvertex\Plugin\Components\Registration\Registration;
use Leadvertex\Plugin\Components\Settings\Settings;
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
abstract class Batch extends Model
{

    /** @var Settings */
    private $settings;

    /** @var self|null */
    private static $current;

    public function __construct(InputTokenInterface $token, ApiFilterSortPaginate $fsp, string $lang)
    {
        parent::__construct($token->getId());

        $this->token = $token;
        $this->lang = $lang;
        $this->fsp = $fsp;

        $this->options = [];

        if ($token->getCompanyId() != $this->getCompanyId()) {
            throw new TokenException('Mismatch token company ID and current company ID');
        }
    }

    public function getToken(): InputTokenInterface
    {
        return $this->token;
    }

    public function getRegistration(): Registration
    {
        return $this->token->getRegistration();
    }

    public function getSettings(): Settings
    {
        if (is_null($this->settings)) {
            $registration = $this->getRegistration();
            $this->settings = Settings::findById($registration->getId(), $registration->getFeature());
            if (is_null($this->settings)) {
                $this->settings = new Settings($registration->getId(), $registration->getFeature());
            }
        }
        return $this->settings;
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

    /**
     * @param Process $process
     * @return mixed
     */
    abstract public function run(Process $process);

    /**
     * @param Batch $batch
     * @throws BatchException
     */
    public static function init(self $batch)
    {
        $current = self::$current ? (string) self::$current->getToken()->getInputToken() : null;
        $new = (string) $batch->getToken()->getInputToken();

        if ($current && $current !== $new) {
            throw new BatchException('Current batch session already exists', 1);
        }

        self::$current = $batch;
    }

    /**
     * @return static
     * @throws BatchException
     */
    public static function current(): self
    {
        if (is_null(self::$current)) {
            throw new BatchException('No batch session started', 404);
        }
        return self::$current;
    }

}