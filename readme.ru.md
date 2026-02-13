# salesrender/plugin-component-batch

Инфраструктура пакетной обработки для экосистемы плагинов SalesRender. Предоставляет модель данных для пакетных операций, контейнер внедрения зависимостей для форм и обработчиков, конечный автомат состояний для отслеживания выполнения, а также CLI-команды для работы с очередью.

## Установка

```bash
composer require salesrender/plugin-component-batch
```

## Требования

- PHP >= 7.4
- Расширение: `ext-json`
- Зависимости:

| Пакет | Версия | Назначение |
|---|---|---|
| `salesrender/plugin-component-db` | ^0.3.5 | Персистентность (базовый класс Model) |
| `salesrender/plugin-component-translations` | ^0.1.1 | Поддержка языков и локалей |
| `salesrender/plugin-component-access` | ^0.1.0 | Управление токенами (GraphqlInputToken) |
| `salesrender/plugin-component-api-client` | ^0.6.0 | API-клиент и фильтрация/сортировка/пагинация |
| `salesrender/plugin-component-form` | ^0.10.0 или ^0.11.0 | Формы и FormData |
| `salesrender/plugin-component-queue` | ^0.3.0 | Базовые классы команд очереди |

## Жизненный цикл пакетной обработки

Полный цикл выполнения пакетной операции:

```
1. Подготовка     POST /batch/prepare     Создание Batch с токеном, FSP, языком
       |
2. Получение      GET  /batch/form/{n}    Получение формы #n из BatchContainer
   формы
       |
3. Отправка       PUT  /batch/form/{n}    Валидация и сохранение FormData в Batch
   данных формы                            (шаги 2-3 повторяются для каждой формы)
       |
4. Запуск         POST /batch/run         Создание Process (состояние: scheduled), постановка в очередь
       |
5. Обработка      CLI  batch:handle {id}  Вызов BatchContainer::getHandler() с Process и Batch
       |
6. Отслеживание   GET  /process/{id}      Возврат Process в JSON (состояние, счетчики, ошибки, результат)
```

## Основные классы

### `Batch`

**Namespace:** `SalesRender\Plugin\Components\Batch`

Персистентная модель, хранящая все данные, необходимые для выполнения пакетной операции. Наследуется от `Model` (из [`plugin-component-db`](https://github.com/SalesRender/plugin-component-db)).

| Метод | Сигнатура | Описание |
|---|---|---|
| `__construct` | `(InputTokenInterface $token, ApiFilterSortPaginate $fsp, string $lang, array $arguments = [])` | Создать пакет с токеном, фильтрами/сортировкой/пагинацией, языком и опциональными аргументами |
| `getToken` | `(): InputTokenInterface` | Получить входной токен (содержит backend URI, ID компании, ссылку на плагин) |
| `getFsp` | `(): ApiFilterSortPaginate` | Получить конфигурацию фильтрации/сортировки/пагинации |
| `getLang` | `(): string` | Получить код языка (например, `'ru_RU'`) |
| `getArguments` | `(): array` | Получить дополнительные аргументы, переданные на этапе подготовки |
| `getOptions` | `(int $number): ?FormData` | Получить данные формы #N или `null` |
| `setOptions` | `(int $number, FormData $data): void` | Сохранить данные формы #N |
| `countOptions` | `(): int` | Получить количество отправленных форм |
| `getApiClient` | `(): ApiClient` | Создать `ApiClient`, настроенный на backend URI и выходной токен |
| `find` | `(): ?Model` | **(static)** Найти пакет для текущего `GraphqlInputToken` |
| `schema` | `(): array` | **(static)** Получить описание схемы базы данных |

### `BatchContainer`

**Namespace:** `SalesRender\Plugin\Components\Batch`

Статический контейнер внедрения зависимостей, хранящий фабрику форм и обработчик. Должен быть сконфигурирован в `bootstrap.php` плагина.

| Метод | Сигнатура | Описание |
|---|---|---|
| `config` | `(callable $forms, BatchHandlerInterface $handler): void` | **(static)** Зарегистрировать фабрику форм и обработчик пакетов |
| `getForm` | `(int $number, array $context = []): ?Form` | **(static)** Получить форму пакета #N, вызывая фабрику; возвращает `null`, когда форм больше нет |
| `getHandler` | `(): BatchHandlerInterface` | **(static)** Получить зарегистрированный обработчик пакетов |

Конструктор закрыт -- `BatchContainer` используется только как статический реестр.

Выбрасывает `BatchContainerException` при обращении до вызова `config()`.

### `BatchHandlerInterface`

**Namespace:** `SalesRender\Plugin\Components\Batch`

Контракт, который должен реализовать обработчик пакетов каждого плагина.

```php
interface BatchHandlerInterface
{
    public function __invoke(Process $process, Batch $batch);
}
```

Обработчик получает `Process` (для отслеживания прогресса) и `Batch` (для доступа к токену, FSP, опциям и API-клиенту). Обработчик отвечает за:

1. Инициализацию процесса с общим количеством: `$process->initialize($count)`
2. Итерацию по данным с вызовом `$process->handle()`, `$process->skip()` или `$process->addError()`
3. Сохранение процесса после каждого элемента: `$process->save()`
4. Завершение процесса: `$process->finish($result)`

### `Process`

**Namespace:** `SalesRender\Plugin\Components\Batch\Process`

Модель конечного автомата, отслеживающая прогресс выполнения пакетной операции. Наследуется от `Model`, реализует `JsonSerializable`.

**Константы состояний:**

| Константа | Значение | Описание |
|---|---|---|
| `STATE_SCHEDULED` | `'scheduled'` | Процесс в очереди, ожидает выполнения |
| `STATE_PROCESSING` | `'processing'` | Процесс активно обрабатывается |
| `STATE_POST_PROCESSING` | `'post_processing'` | Основная обработка завершена, выполняется финализация |
| `STATE_ENDED` | `'ended'` | Процесс завершен (успешно или с ошибкой) |

**Переходы состояний:** `scheduled` --> `processing` (через `initialize()`) --> `post_processing` (через `setState()`) --> `ended` (через `finish()` или `terminate()`)

| Метод | Сигнатура | Описание |
|---|---|---|
| `__construct` | `(PluginReference $reference, string $id, string $description = null)` | Создать процесс в состоянии `scheduled` |
| `getCompanyId` | `(): int` | Получить ID компании |
| `getPluginId` | `(): int` | Получить ID плагина |
| `getCreatedAt` | `(): int` | Получить временную метку создания |
| `getState` | `(): string` | Получить текущее состояние |
| `setState` | `(string $state): void` | Перейти в новое состояние |
| `getUpdatedAt` | `(): int` | Получить временную метку последнего обновления |
| `getDescription` | `(): ?string` | Получить описание процесса |
| `setDescription` | `(?string $description): void` | Установить или обновить описание процесса |
| `initialize` | `(?int $init): void` | Задать ожидаемое общее количество элементов и перейти в состояние `processing` |
| `isInitialized` | `(): bool` | Проверить, был ли процесс инициализирован |
| `getInitializedAt` | `(): ?int` | Получить временную метку инициализации |
| `handle` | `(): void` | Увеличить счетчик обработанных (требуется инициализация, процесс не должен быть завершен) |
| `getHandledCount` | `(): int` | Получить количество успешно обработанных элементов |
| `skip` | `(): void` | Увеличить счетчик пропущенных |
| `getSkippedCount` | `(): int` | Получить количество пропущенных элементов |
| `addError` | `(Error $error): void` | Увеличить счетчик ошибок и сохранить ошибку (хранятся последние 20) |
| `getFailedCount` | `(): int` | Получить количество элементов с ошибками |
| `getLastErrors` | `(): array` | Получить последние ошибки (до 20) в виде объектов `Error`, от новых к старым |
| `getResult` | `(): int\|string\|bool\|null` | Получить финальный результат |
| `finish` | `($value): void` | Завершить процесс с результатом (`bool`, `int` или `string`). Оставшиеся элементы автоматически считаются пропущенными |
| `terminate` | `(Error $error): void` | Аварийно завершить процесс с ошибкой. Оставшиеся элементы автоматически считаются ошибочными |
| `jsonSerialize` | `(): array` | Сериализовать состояние процесса для API отслеживания |

### `Error`

**Namespace:** `SalesRender\Plugin\Components\Batch\Process`

Простой объект-значение, представляющий ошибку, произошедшую при пакетной обработке.

| Метод | Сигнатура | Описание |
|---|---|---|
| `__construct` | `(string $message, string $entityId = null)` | Создать ошибку с сообщением и опциональным ID сущности |
| `getMessage` | `(): string` | Получить сообщение об ошибке |
| `getEntityId` | `(): ?string` | Получить ID связанной сущности (например, ID заказа) |

### CLI-команды

#### `BatchQueueCommand`

**Namespace:** `SalesRender\Plugin\Components\Batch\Commands`

Консольная команда (`batch:queue`), которая опрашивает процессы в состоянии `scheduled` и запускает рабочие процессы обработчиков. Наследуется от `QueueCommand` из [`plugin-component-queue`](https://github.com/SalesRender/plugin-component-queue). Параллелизм управляется переменной окружения `LV_PLUGIN_QUEUE_LIMIT`.

#### `BatchHandleCommand`

**Namespace:** `SalesRender\Plugin\Components\Batch\Commands`

Консольная команда (`batch:handle {id}`), которая загружает `Batch` по ID, настраивает контекст токена/коннектора/переводчика и вызывает `BatchContainer::getHandler()`. При необработанных исключениях завершает процесс с фатальной ошибкой перед повторным выбросом исключения.

### `BatchContainerException`

**Namespace:** `SalesRender\Plugin\Components\Batch\Exceptions`

Выбрасывается при вызове `BatchContainer::getForm()` или `BatchContainer::getHandler()` до вызова `BatchContainer::config()`.

## Примеры использования

### Настройка BatchContainer в bootstrap.php

Из `plugin-macros-example`:

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

Из `plugin-logistic-example`:

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

### Реализация BatchHandlerInterface

Из `plugin-macros-example` (`ExampleHandler`):

```php
use SalesRender\Plugin\Components\Batch\Batch;
use SalesRender\Plugin\Components\Batch\BatchHandlerInterface;
use SalesRender\Plugin\Components\Batch\Process\Error;
use SalesRender\Plugin\Components\Batch\Process\Process;

class ExampleHandler implements BatchHandlerInterface
{
    public function __invoke(Process $process, Batch $batch)
    {
        // 1. Чтение опций пакета (данные формы, отправленные пользователем)
        $delay = $batch->getOptions(1)->get('response_options.delay');

        // 2. Создание iterator по заказам
        $iterator = new OrdersFetcherIterator(
            Columns::getQueryColumns($fields),
            $batch->getApiClient(),
            $batch->getFsp()
        );

        // 3. Инициализация процесса с общим количеством
        $process->initialize(count($iterator));

        // 4. Обработка каждого элемента
        foreach ($iterator as $order) {
            $process->handle();
            $process->save();
        }

        // 5. Опциональное состояние постобработки
        $process->setState(Process::STATE_POST_PROCESSING);
        $process->save();

        // 6. Завершение с результатом
        $process->finish(true);
        $process->save();
    }
}
```

### Полный обработчик с обработкой ошибок

Из `plugin-macros-fields-cleaner` (`OrdersHandler`):

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

        // Проверка: лимит на максимальное количество заказов
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

### Возврат URL файла в качестве результата

Из `plugin-macros-excel` (`ExcelHandler`):

```php
// После записи Excel-файла...
$process->finish((string) $fileUri);
$process->save();
```

Когда `finish()` получает строку, она интерпретируется как URL для скачивания, отображаемый пользователю.

### Аварийное завершение процесса при фатальной ошибке

Из `plugin-component-batch` (`BatchHandleCommand`):

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

### Создание Batch (на стороне платформы)

Из `plugin-core` (`BatchPrepareAction`):

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

### Запуск Batch

Из `plugin-core` (`BatchRunAction`):

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

// В режиме отладки -- синхронное выполнение:
$process->setState(Process::STATE_PROCESSING);
$process->save();
BatchContainer::getHandler()($process, $batch);
```

## JSON-сериализация Process

Вывод `Process::jsonSerialize()`, используемый endpoint отслеживания:

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

## Конфигурация

### Переменные окружения

| Переменная | Описание |
|---|---|
| `LV_PLUGIN_QUEUE_LIMIT` | Максимальное количество одновременных рабочих процессов пакетной обработки (используется в `BatchQueueCommand`) |
| `LV_PLUGIN_DEBUG` | При значении `1` пакетная обработка выполняется синхронно в действии запуска (без очереди) |

## Смотрите также

- [salesrender/plugin-component-api-client](https://github.com/SalesRender/plugin-component-api-client) -- GraphQL API клиент и fetcher iterator
- [salesrender/plugin-component-db](https://github.com/SalesRender/plugin-component-db) -- Базовый класс модели для работы с БД
- [salesrender/plugin-component-form](https://github.com/SalesRender/plugin-component-form) -- Формы и FormData
- [salesrender/plugin-component-queue](https://github.com/SalesRender/plugin-component-queue) -- Базовые классы команд очереди
- [salesrender/plugin-component-access](https://github.com/SalesRender/plugin-component-access) -- Управление токенами
- [salesrender/plugin-component-translations](https://github.com/SalesRender/plugin-component-translations) -- Переводы и поддержка локалей
