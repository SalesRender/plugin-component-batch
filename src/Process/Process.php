<?php
/**
 * Created for plugin-component-batch
 * Datetime: 25.07.2019 12:00
 * @author Timur Kasumov aka XAKEPEHOK
 */

namespace Leadvertex\Plugin\Components\Batch\Process;


use InvalidArgumentException;
use JsonSerializable;
use Leadvertex\Plugin\Components\Db\Components\PluginReference;
use Leadvertex\Plugin\Components\Db\Model;
use LogicException;
use RuntimeException;

class Process extends Model implements JsonSerializable
{

    protected int $companyId;

    protected int $pluginId;

    protected int $createdAt;

    protected string $state;

    protected int $updatedAt;

    protected ?int $initialized = null;

    protected ?int $initializedAt = null;

    protected int $handled = 0;

    protected int $skipped = 0;

    protected int $failed = 0;

    protected array $errors = [];

    /** @var int|string|null */
    protected $result = null;

    protected ?string $description = null;

    const STATE_SCHEDULED = 'scheduled';
    const STATE_PROCESSING = 'processing';
    const STATE_POST_PROCESSING = 'post_processing';
    const STATE_ENDED = 'ended';

    public function __construct(PluginReference $reference, string $id, string $description = null)
    {
        $this->companyId = (int) $reference->getCompanyId();
        $this->pluginId = (int) $reference->getId();
        $this->id = $id;
        $this->createdAt = time();
        $this->setState(self::STATE_SCHEDULED);
        $this->description = $description;
    }

    public function getCompanyId(): int
    {
        return $this->companyId;
    }

    public function getPluginId(): int
    {
        return $this->pluginId;
    }

    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): void
    {
        $this->guardFinished();
        $stateList = [self::STATE_SCHEDULED, self::STATE_PROCESSING, self::STATE_POST_PROCESSING, self::STATE_ENDED];
        if (!in_array($state, $stateList)) {
            throw new InvalidArgumentException('Invalid process state: ' . $state);
        }

        $this->state = $state;
        $this->updatedAt = time();
    }

    public function getUpdatedAt(): int
    {
        return $this->updatedAt;
    }

    /**
     * @return string|null
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @param string|null $description
     */
    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function initialize(?int $init): void
    {
        if ($this->initializedAt !== null) {
            throw new RuntimeException('Process already initialised');
        }

        $this->initialized = $init;
        $this->initializedAt = time();

        $this->setState(self::STATE_PROCESSING);
    }

    public function getInitializedAt(): ?int
    {
        return $this->initializedAt;
    }

    public function isInitialized(): bool
    {
        return $this->initializedAt !== null;
    }

    public function getHandledCount(): int
    {
        return $this->handled;
    }

    public function handle(): void
    {
        $this->guardInitialized();
        $this->guardFinished();
        $this->handled++;
    }

    public function getSkippedCount(): int
    {
        return $this->skipped;
    }

    public function skip(): void
    {
        $this->guardInitialized();
        $this->guardFinished();
        $this->skipped++;
    }

    public function getFailedCount(): int
    {
        return $this->failed;
    }

    public function getLastErrors(): array
    {
        return array_map(function (array $value) {
            return new Error($value['message'], $value['entityId']);
        }, array_reverse($this->errors));
    }

    public function addError(Error $error): void
    {
        $this->guardFinished();
        $this->failed++;
        if (count($this->errors) >= 20) {
            array_shift($this->errors);
        }

        $this->errors[] = [
            'message' => $error->getMessage(),
            'entityId' => $error->getEntityId(),
        ];
    }

    public function getResult()
    {
        return $this->result;
    }

    public function terminate(Error $error): void
    {
        $this->guardFinished();
        $this->addError($error);
        if ($this->initialized > 0) {
            $this->failed += $this->initialized - $this->handled - $this->failed - $this->skipped;
        }
        $this->setState(self::STATE_ENDED);
        $this->result = false;
    }

    public function finish($value): void
    {
        $this->guardFinished();
        $this->guardInitialized();

        if (!is_bool($value) && !is_int($value) && !is_string($value)) {
            throw new InvalidArgumentException("Finish value should be a 'bool', 'int' or 'string' type");
        }

        if ($this->initialized > 0) {
            $skipped = $this->initialized - $this->handled - $this->failed - $this->skipped;
            if ($skipped > 0) {
                $this->skipped+= $skipped;
            }
        }

        $this->setState(self::STATE_ENDED);
        $this->result = $value;
    }

    /**
     * @inheritDoc
     */
    public function jsonSerialize()
    {
        $init = null;
        if ($this->isInitialized()) {
            $init = [
                'timestamp' => $this->initializedAt,
                'value' => $this->initialized
            ];
        }

        $result = null;
        if (!is_null($this->result)) {
            $result = [
                'timestamp' => $this->updatedAt,
                'value' => $this->result,
            ];
        }

        return [
            'companyId' => $this->companyId,
            'pluginId' => $this->pluginId,
            'description' => $this->description,
            'state' => [
                'timestamp' => $this->updatedAt,
                'value' => $this->getState()
            ],
            'initialized' => $init,
            'handled' => $this->handled,
            'skipped' => $this->skipped,
            'failed' => [
                'count' => $this->failed,
                'last' => $this->errors,
            ],
            'result' => $result,
        ];
    }

    private function guardInitialized(): void
    {
        if ($this->initializedAt === null) {
            throw new RuntimeException('Process is not initialized');
        }
    }

    private function guardFinished(): void
    {
        if ($this->result !== null) {
            throw new LogicException('Process already finished and can not be changed');
        }
    }

    protected static function beforeWrite(array $data): array
    {
        $data['errors'] = json_encode($data['errors']);
        return $data;
    }

    protected static function afterRead(array $data): array
    {
        $data['errors'] = json_decode($data['errors']);
        return $data;
    }

    public static function schema(): array
    {
        return [
            'companyId' => ['INT', 'NOT NULL'],
            'pluginId' => ['INT', 'NOT NULL'],
            'createdAt' => ['INT', 'NOT NULL'],
            'state' => ['VARCHAR(20)', 'NOT NULL'],
            'updatedAt' => ['INT'],
            'initialized' => ['INT'],
            'initializedAt' => ['INT'],
            'handled' => ['INT', 'NOT NULL'],
            'skipped' => ['INT', 'NOT NULL'],
            'failed' => ['INT', 'NOT NULL'],
            'errors' => ['TEXT'],
            'result' => ['VARCHAR(512)'],
            'description' => ['TEXT'],
        ];
    }
}