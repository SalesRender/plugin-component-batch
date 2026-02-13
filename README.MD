# salesrender/plugin-component-batch

Batch processing infrastructure for the SalesRender plugin ecosystem. Provides the data model for batch operations, a dependency injection container for forms and handlers, a process state machine for tracking execution progress, and CLI commands for queue-based processing.

## Installation

```bash
composer require salesrender/plugin-component-batch
```

## Requirements

- PHP >= 7.4
- Extension: `ext-json`
- Dependencies:

| Package | Version | Purpose |
|---|---|---|
| `salesrender/plugin-component-db` | ^0.3.5 | Database persistence (Model base class) |
| `salesrender/plugin-component-translations` | ^0.1.1 | Language/locale support |
| `salesrender/plugin-component-access` | ^0.1.0 | Token management (GraphqlInputToken) |
| `salesrender/plugin-component-api-client` | ^0.6.0 | API client and filter/sort/paginate |
| `salesrender/plugin-component-form` | ^0.10.0 or ^0.11.0 | Form and FormData |
| `salesrender/plugin-component-queue` | ^0.3.0 | Queue commands base classes |

## Batch Processing Flow

The complete lifecycle of a batch operation:

```
1. Prepare       POST /batch/prepare     Creates Batch with token, FSP, language
       |
2. Get Form      GET  /batch/form/{n}    Retrieves batch form #n from BatchContainer
       |
3. Submit Data   PUT  /batch/form/{n}    Validates and stores FormData on Batch
       |                                  (repeat steps 2-3 for each form)
       |
4. Run           POST /batch/run         Creates Process (state: scheduled), queues execution
       |
5. Handle        CLI  batch:handle {id}  BatchContainer::getHandler() is invoked with Process and Batch
       |
6. Track         GET  /process/{id}      Returns Process as JSON (state, counters, errors, result)
```

## Key Classes

### `Batch`

**Namespace:** `SalesRender\Plugin\Components\Batch`

Persisted model that stores all data needed to execute a batch operation. Extends `Model` (from [`plugin-component-db`](https://github.com/SalesRender/plugin-component-db)).

| Method | Signature | Description |
|---|---|---|
| `__construct` | `(InputTokenInterface $token, ApiFilterSortPaginate $fsp, string $lang, array $arguments = [])` | Create a batch with token, filters/sort/pagination, language, and optional arguments |
| `getToken` | `(): InputTokenInterface` | Return the input token (contains backend URI, company ID, plugin reference) |
| `getFsp` | `(): ApiFilterSortPaginate` | Return the filter/sort/paginate configuration |
| `getLang` | `(): string` | Return the language code (e.g. `'ru_RU'`) |
| `getArguments` | `(): array` | Return additional arguments passed at preparation time |
| `getOptions` | `(int $number): ?FormData` | Return submitted form data for form #N, or `null` |
| `setOptions` | `(int $number, FormData $data): void` | Store submitted form data for form #N |
| `countOptions` | `(): int` | Return the number of submitted forms |
| `getApiClient` | `(): ApiClient` | Create an `ApiClient` configured with the token's backend URI and output token |
| `find` | `(): ?Model` | **(static)** Find the batch for the current `GraphqlInputToken` |
| `schema` | `(): array` | **(static)** Return the database schema definition |

### `BatchContainer`

**Namespace:** `SalesRender\Plugin\Components\Batch`

Static dependency injection container that holds the form factory and handler. Must be configured in the plugin's `bootstrap.php`.

| Method | Signature | Description |
|---|---|---|
| `config` | `(callable $forms, BatchHandlerInterface $handler): void` | **(static)** Register the form factory and batch handler |
| `getForm` | `(int $number, array $context = []): ?Form` | **(static)** Get batch form #N by calling the factory; returns `null` when no more forms |
| `getHandler` | `(): BatchHandlerInterface` | **(static)** Get the registered batch handler |

The constructor is private -- `BatchContainer` is used as a static registry only.

Throws `BatchContainerException` if accessed before `config()` is called.

### `BatchHandlerInterface`

**Namespace:** `SalesRender\Plugin\Components\Batch`

The contract that every plugin's batch handler must implement.

```php
interface BatchHandlerInterface
{
    public function __invoke(Process $process, Batch $batch);
}
```

The handler receives the `Process` (for tracking progress) and `Batch` (for accessing token, FSP, options, and API client). The handler is responsible for:

1. Initializing the process with a count: `$process->initialize($count)`
2. Iterating over data and calling `$process->handle()`, `$process->skip()`, or `$process->addError()`
3. Saving the process after each item: `$process->save()`
4. Finishing the process: `$process->finish($result)`

### `Process`

**Namespace:** `SalesRender\Plugin\Components\Batch\Process`

State machine model that tracks the execution progress of a batch operation. Extends `Model`, implements `JsonSerializable`.

**State constants:**

| Constant | Value | Description |
|---|---|---|
| `STATE_SCHEDULED` | `'scheduled'` | Process is queued, waiting for execution |
| `STATE_PROCESSING` | `'processing'` | Process is actively being handled |
| `STATE_POST_PROCESSING` | `'post_processing'` | Main processing done, performing cleanup/finalization |
| `STATE_ENDED` | `'ended'` | Process has completed (success or failure) |

**State transitions:** `scheduled` --> `processing` (via `initialize()`) --> `post_processing` (via `setState()`) --> `ended` (via `finish()` or `terminate()`)

| Method | Signature | Description |
|---|---|---|
| `__construct` | `(PluginReference $reference, string $id, string $description = null)` | Create process in `scheduled` state |
| `getCompanyId` | `(): int` | Return the company ID |
| `getPluginId` | `(): int` | Return the plugin ID |
| `getCreatedAt` | `(): int` | Return creation timestamp |
| `getState` | `(): string` | Return the current state |
| `setState` | `(string $state): void` | Transition to a new state |
| `getUpdatedAt` | `(): int` | Return the last update timestamp |
| `getDescription` | `(): ?string` | Return the process description |
| `setDescription` | `(?string $description): void` | Set or update the process description |
| `initialize` | `(?int $init): void` | Set the total expected items count and transition to `processing` |
| `isInitialized` | `(): bool` | Check if the process has been initialized |
| `getInitializedAt` | `(): ?int` | Return the initialization timestamp |
| `handle` | `(): void` | Increment the handled counter (requires initialized, not finished) |
| `getHandledCount` | `(): int` | Return the number of successfully handled items |
| `skip` | `(): void` | Increment the skipped counter |
| `getSkippedCount` | `(): int` | Return the number of skipped items |
| `addError` | `(Error $error): void` | Increment failed counter and store the error (keeps last 20) |
| `getFailedCount` | `(): int` | Return the number of failed items |
| `getLastErrors` | `(): array` | Return the last errors (up to 20) as `Error` objects, newest first |
| `getResult` | `(): int\|string\|bool\|null` | Return the final result |
| `finish` | `($value): void` | End the process with a result (`bool`, `int`, or `string`). Auto-counts remaining as skipped |
| `terminate` | `(Error $error): void` | Abort the process with an error. Auto-counts remaining as failed |
| `jsonSerialize` | `(): array` | Serialize process state for the tracking API response |

### `Error`

**Namespace:** `SalesRender\Plugin\Components\Batch\Process`

Simple value object representing an error that occurred during batch processing.

| Method | Signature | Description |
|---|---|---|
| `__construct` | `(string $message, string $entityId = null)` | Create an error with a message and optional entity ID |
| `getMessage` | `(): string` | Return the error message |
| `getEntityId` | `(): ?string` | Return the associated entity ID (e.g. order ID) |

### CLI Commands

#### `BatchQueueCommand`

**Namespace:** `SalesRender\Plugin\Components\Batch\Commands`

Console command (`batch:queue`) that polls for processes in `scheduled` state and spawns handler workers. Extends `QueueCommand` from [`plugin-component-queue`](https://github.com/SalesRender/plugin-component-queue). Concurrency is controlled by the `LV_PLUGIN_QUEUE_LIMIT` environment variable.

#### `BatchHandleCommand`

**Namespace:** `SalesRender\Plugin\Components\Batch\Commands`

Console command (`batch:handle {id}`) that loads a `Batch` by ID, sets up the token/connector/translator context, and invokes `BatchContainer::getHandler()`. On uncaught exceptions, terminates the process with a fatal error before re-throwing.

### `BatchContainerException`

**Namespace:** `SalesRender\Plugin\Components\Batch\Exceptions`

Thrown when `BatchContainer::getForm()` or `BatchContainer::getHandler()` is called before `BatchContainer::config()`.

## Usage Examples

### Configuring BatchContainer in bootstrap.php

From `plugin-macros-example`:

```php
use SalesRender\Plugin\Components\Batch\BatchContainer;

BatchContainer::config(
    function (int $number) {
        switch ($number) {
            case 1: return new ResponseOptionsForm();
            case 2: return new SecondResponseOptionsForm();
            case 3: return new PreviewOptionsForm();
            default: return null;
        }
    },
    new ExampleHandler()
);
```

From `plugin-logistic-example`:

```php
use SalesRender\Plugin\Components\Batch\BatchContainer;

BatchContainer::config(
    function (int $number) {
        switch ($number) {
            case 1: return new Batch_1();
            default: return null;
        }
    },
    new BatchShippingHandler()
);
```

### Implementing a BatchHandlerInterface

From `plugin-macros-example` (`ExampleHandler`):

```php
use SalesRender\Plugin\Components\Batch\Batch;
use SalesRender\Plugin\Components\Batch\BatchHandlerInterface;
use SalesRender\Plugin\Components\Batch\Process\Error;
use SalesRender\Plugin\Components\Batch\Process\Process;

class ExampleHandler implements BatchHandlerInterface
{
    public function __invoke(Process $process, Batch $batch)
    {
        // 1. Read batch options (form data submitted by user)
        $delay = $batch->getOptions(1)->get('response_options.delay');

        // 2. Create an iterator over orders
        $iterator = new OrdersFetcherIterator(
            Columns::getQueryColumns($fields),
            $batch->getApiClient(),
            $batch->getFsp()
        );

        // 3. Initialize process with total count
        $process->initialize(count($iterator));

        // 4. Process each item
        foreach ($iterator as $order) {
            $process->handle();
            $process->save();
        }

        // 5. Optional post-processing state
        $process->setState(Process::STATE_POST_PROCESSING);
        $process->save();

        // 6. Finish with a result
        $process->finish(true);
        $process->save();
    }
}
```

### Complete Handler with Error Handling

From `plugin-macros-fields-cleaner` (`OrdersHandler`):

```php
use SalesRender\Plugin\Components\Batch\Batch;
use SalesRender\Plugin\Components\Batch\BatchHandlerInterface;
use SalesRender\Plugin\Components\Batch\Process\Error;
use SalesRender\Plugin\Components\Batch\Process\Process;
use SalesRender\Plugin\Components\ApiClient\ApiClient;
use SalesRender\Plugin\Components\Access\Token\GraphqlInputToken;

class OrdersHandler implements BatchHandlerInterface
{
    private ApiClient $client;

    public function __invoke(Process $process, Batch $batch)
    {
        $token = GraphqlInputToken::getInstance();
        $this->client = new ApiClient(
            "{$token->getBackendUri()}companies/{$token->getPluginReference()->getCompanyId()}/CRM",
            (string) $token->getOutputToken()
        );

        $orderFields = [
            'orders' => [
                'id',
                'status' => ['id'],
            ]
        ];

        $ordersIterator = new OrdersFetcherIterator(
            $orderFields,
            $batch->getApiClient(),
            $batch->getFsp()
        );

        $ordersCount = count($ordersIterator);

        // Guard: check max orders limit
        if ($ordersCount > $maximumOrdersCount) {
            $process->terminate(new Error('Maximum orders count exceeded'));
            $process->save();
            return;
        }

        $process->initialize($ordersCount);

        $query = <<<QUERY
mutation updateOrder(\$input: UpdateOrderInput!) {
  orderMutation {
    updateOrder(input: \$input) {
      id
    }
  }
}
QUERY;

        foreach ($ordersIterator as $id => $order) {
            try {
                $response = $this->client->query($query, [
                    'input' => ['id' => $id]
                ]);

                if ($response->hasErrors()) {
                    throw new \Exception($response->getErrors()[0]['message']);
                }

                $process->handle();
            } catch (\Exception $exception) {
                $process->addError(new Error(
                    $exception->getMessage(),
                    $id
                ));
            }
            $process->save();
        }

        $process->finish(true);
        $process->save();
    }
}
```

### Returning a File URL as Result

From `plugin-macros-excel` (`ExcelHandler`):

```php
// After writing an Excel file...
$process->finish((string) $fileUri);
$process->save();
```

When `finish()` receives a string, it is treated as a download URL displayed to the user.

### Terminating a Process on Fatal Error

From `plugin-component-batch` (`BatchHandleCommand`):

```php
try {
    $handler = BatchContainer::getHandler();
    $handler($process, $batch);
} catch (\Throwable $exception) {
    $error = new Error('Fatal plugin error. Please contact plugin developer.');
    $process->terminate($error);
    $process->save();
    throw $exception;
}
```

### Creating a Batch (Platform Side)

From `plugin-core` (`BatchPrepareAction`):

```php
use SalesRender\Plugin\Components\Access\Token\GraphqlInputToken;
use SalesRender\Plugin\Components\ApiClient\ApiFilterSortPaginate;
use SalesRender\Plugin\Components\ApiClient\ApiSort;
use SalesRender\Plugin\Components\Batch\Batch;
use SalesRender\Plugin\Components\Translations\Translator;

$sort = new ApiSort($sort['field'], $sort['direction']);

$batch = new Batch(
    GraphqlInputToken::getInstance(),
    new ApiFilterSortPaginate($filters, $sort, 100),
    Translator::getLang(),
    $arguments
);
$batch->save();
```

### Running a Batch

From `plugin-core` (`BatchRunAction`):

```php
use SalesRender\Plugin\Components\Batch\Batch;
use SalesRender\Plugin\Components\Batch\BatchContainer;
use SalesRender\Plugin\Components\Batch\Process\Process;
use SalesRender\Plugin\Components\Access\Token\GraphqlInputToken;

$batch = Batch::find();
$process = new Process(
    GraphqlInputToken::getInstance()->getPluginReference(),
    GraphqlInputToken::getInstance()->getId(),
);
$process->save();

// In debug mode, execute synchronously:
$process->setState(Process::STATE_PROCESSING);
$process->save();
BatchContainer::getHandler()($process, $batch);
```

## Process JSON Serialization

The `Process::jsonSerialize()` output, used by the tracking endpoint:

```json
{
  "companyId": 42,
  "pluginId": 7,
  "description": "Export orders to Excel",
  "state": {
    "timestamp": 1700000000,
    "value": "processing"
  },
  "initialized": {
    "timestamp": 1700000001,
    "value": 150
  },
  "handled": 100,
  "skipped": 5,
  "failed": {
    "count": 3,
    "last": [
      {"message": "Order not found", "entityId": "12345"}
    ]
  },
  "result": null
}
```

## Configuration

### Environment Variables

| Variable | Description |
|---|---|
| `LV_PLUGIN_QUEUE_LIMIT` | Maximum number of concurrent batch workers (used by `BatchQueueCommand`) |
| `LV_PLUGIN_DEBUG` | When set to `1`, batch is executed synchronously in the run action (no queue) |

## See Also

- [salesrender/plugin-component-api-client](https://github.com/SalesRender/plugin-component-api-client) -- GraphQL API client and fetcher iterator
- [salesrender/plugin-component-db](https://github.com/SalesRender/plugin-component-db) -- Database Model base class
- [salesrender/plugin-component-form](https://github.com/SalesRender/plugin-component-form) -- Form and FormData
- [salesrender/plugin-component-queue](https://github.com/SalesRender/plugin-component-queue) -- Queue command base classes
- [salesrender/plugin-component-access](https://github.com/SalesRender/plugin-component-access) -- Token management
- [salesrender/plugin-component-translations](https://github.com/SalesRender/plugin-component-translations) -- Translations and locale support
