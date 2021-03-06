# Access

Система управления правами доступа + проверка типов аргументов.

#### Установка

``
composer reqire scaleplan/access
``

<br>

#### Инициализация

```
cd vendor/scaleplan/access

./init schema data
```

где schema и data необязательные параметры указывающие на необходимость генерации схемы Access в базе данных и урлов API, файлов и введенных в конфигурации соответственно.

<br>

#### Механика работы

Вызывается метод контроллера извне класса контроллера. Каким образом это происходит неважно. 

Если метод публичный, либо в комментарии в методу указано значение директивы <i>access_no_rights_check</i>
конфигурации, система не задействуется и выполнение происходит как обычно. 
Если метод приватный (модификаторы доступа private и protected) и если  указан специальный phpdoc-тег обработки метода системой Access (значение директивы access_label конфигурации), то происходит запрос к базе данных, проверящий возможность исполнения метода с пришедшими параметрами и для определенного пользователя (идентификатор пользователя задается при создании объектов Access).

Например:

```
class User
{
    /**
     * Get user object
     *
     * @accessMethod
     *
     * @param int $id - user identifier
     *
     * @return UserAbstract
     */
    protected static function get(int $id): UserAbstract
    {
        // ...
    }
}
```

В данном примере будет проверяться доступ текущего пользователя к статическому методу get класса User для любых значений аргумента $id.

Однако, доступ можно определить для выполнения метода с определенными аргументами:

```
     /**
     * Get user object
     *
     * @accessMethod
     *
     * @accessFilter id
     *
     * @param int $id - user identifier
     *
     * @return UserAbstract
     */
    protected static function actionGet(int $id): UserAbstract
    {
        // ...
    }
```

В этом примере доступ будет разрешен только если значение фильтрующего аргумента $id входит в список разрешенных значений, хранящийся в базе данных (столбец <i>values</i> таблицы <i>access_right</i>). А список в базе данных будет иметь вид:

``
ARRAY['<значение фильтра 1>, <значение фильтра 2', ...]
``

Фильтровать можно и по нескольким аргументам:

```
     /**
     * Set user role
     *
     * @accessMethod
     *
     * @accessFilter id, role
     *
     * @param int $id - user identifier
     * @param string $role - user role
     *
     * @return void
     */
    protected static function actionSetRole(int $id, string $role): void
    {
        // ...
    }
```

В этом случае в список разрешенных начений будет иметь формат:

```
ARRAY['<значение для первого фильтра><разделитель><значение для второго фильтра>...', ...]
```

Таким образом для того чтобы разрешить выполнение метода ```User::setRole(21, 'Moderator')``` необходимо чтобы в списке разрешенных значений было значение `21:Moderator`, для раздклителя по умолчанию <b>:</b>

Модуль поддерживает проверку типов входных параметров. Php 7 поддерживает type hinting для проверки типов, однако, Access действует более интеллектуально:

1. В PHP аргументы метода и возвращаемый тип могут иметь только один тип:
 
``
 protected static function setRole(int $id, string $role): void
``
 
Если же мы хотим типизацию с несколькими типами как, например, в C# или TypeScript:
 
```
 setMultiData(object: HTMLElement | NodeList, data: Object | Object[] | string = this.data): HTMLElement | NodeList
 
```
 
то нативный PHP не позволит вам это сделать.
 
Подсистема проверки типов Access может ориентироваться на <i>PHPDOC</i> и проверять значения на соответствие нескольким типам если они указаны в <i>PHPDOC</i>:

```
     /**
     * Set user role
     *
     * @accessMethod
     *
     * @accessFilter id, role
     *
     * @param int|string $id - user identifier
     * @param string|IRole $role - user role
     *
     * @return UserAbstract|void
     */
    protected static function actionSetRole(int $id, string $role)
    {
        // ...
    }
```

2. По умолчанию значение аргумента может считаться "правильным", даже если его тип не соответствует ожидаемому, но значение, приведенное к ожидаемому типу (или к одному из ожидаемых) не отличается от исходного при нечетком сравнении (==). Это поведение можно отключить задав тег из директивы конфигурации <i>deny_fuzzy</i> для метода.

Этот функционал доступен Как для методов контроллеров такое для методов моделей.
<br>

Модуль поддерживает генерацию урлов на методы API из файлов контроллеров.

Для этого необходимо лишь задать необходимые директивы конфигурации в файле конфигурации Access.

```
controllers:
  - path: /var/www/project/controllers
    method_prefix: action
    namespace: App\controllers
``` 

После генерации автоматически заполняется таблица <i>access.url</i>
<br>

В конфигурационном файле можно задать роли пользователей системы

```
roles:
  - Администратор
  - Модератор
  - Слушатель
  - Гость
```
Зачем эти роли можно привязать к реально существующим пользователям, зарегистрированным в системе и выставить права доступа по умолчанию для каждой роли.

Не смотря на это, далее права доступа для любого пользователя можно менять независимо от начального набора прав - права доступа по умолчанию существуют лишь чтобы задать можно было автоматически выдать набор прав пользователю.

Модуль поддерживает управление правами доступа для приватных файлов. Механизм работы такой же как и для API. По сути, системе всё равно работает она с урлами для методов контроллеров или с углами приватных файлов. Для генерации ссылок на файлы надо лишь задать в конфиге директории в которых эти файлы хранятся:

```
files:
    - /var/www/project/view/private/materials
```

Дополнительные урлы для проверки можно задать просто записав их в конфигурационный файл в директиву urls:

```
urls:
  - /var/www/project/file.jpg
  - /var/www/project/get-a-lot-of-money
```

<br>

Для корректной работы с методами котроллеров необходимо, чтобы обрабатываемые классы контроллеров наследовались от класса AccessControllerParent. Для проверки аргументов методов моделей надо необходимо наследовать классы моделей от класса AccessServiceParent.
<br>

Основное хранилище данных системы это PostgreSQL. Однако данные необходимые для проверки прав доступа кэшируются в хранилище Redis. Для увеличения производительности. 

При изменении данных в основном хранилище (PostgreSQL) данные в кэше (Redis) автоматически обновляются по триггеру. Для того, чтобы триггер корректно выполнялся пользователь процесса PostgreSQL должен иметь доступ к хранилищу Redis.

<br>

##### Дополнительный функционал:

1. Поддерживает добавление коллбеков выполняющихся до и после успешного исполнения метода контроллера. При этом эти колбеки могут менять входные данные и получившийся результат соответственно.

2. Во время инициализации модуль выкачивает из базы данных название всех таблиц базы данных. В дальненйшем доступ к этим таблицам будет обрабатываться подсистемой проверки прав. Вы может как угодно править эту информацию в базе данных.

3. Дополнительно в БД можно задать тип метода контроллера, он же тип методы API, чтобы знать какой метод будет изменяющим, удаляющим, создающим или читающим, это может быть удобно для фильтрации методов контроллеров в пользовательском интерфейсе.

<br>

[Документация классов](docs_ru)
