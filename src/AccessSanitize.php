<?php

namespace Scaleplan\Access;

use phpDocumentor\Reflection\DocBlock;
use Scaleplan\Access\Constants\ConfigConstants;
use Scaleplan\Access\Exceptions\AccessException;
use Scaleplan\Access\Exceptions\ValidationException;
use Scaleplan\DTO\DTO;

/**
 * Класс проверки аргументов выполнения
 *
 * Class AccessSanitize
 *
 * @package Scaleplan\Access
 */
class AccessSanitize
{
    /**
     * Отражение метода или SQL-свойства
     *
     * @var \ReflectionMethod|\ReflectionProperty
     */
    protected $reflector;

    /**
     * Массив аргументов
     *
     * @var array
     */
    protected $args = [];

    /**
     * Массив очещенных аргументов
     *
     * @var array|null
     */
    protected $sanitizedArgs;

    /**
     * Конструктор
     *
     * @param \Reflector $reflector - отражение метода или SQL-свойства
     * @param array $args - массив аргументов
     *
     * @throws AccessException
     */
    public function __construct(\Reflector $reflector, array $args)
    {
        if (!($reflector instanceof \ReflectionMethod) && !($reflector instanceof \ReflectionProperty)) {
            throw new AccessException('Почистить можно только параметры методов и SQL-свойств');
        }

        $this->reflector = $reflector;
        $this->args = $args;
    }

    /**
     * Проверить и очистить аргументы
     *
     * @return array
     *
     * @throws AccessException
     */
    public function sanitizeArgs(): array
    {
        if ($this->sanitizedArgs !== null) {
            return $this->sanitizedArgs;
        }

        if ($this->reflector instanceof \ReflectionMethod) {
            return $this->sanitizedArgs = self::sanitizeMethodArgs($this->reflector, $this->args);
        }

        return $this->sanitizedArgs = self::sanitizeSQLPropertyArgs($this->reflector, $this->args);
    }

    /**
     * Проверить аргументы метода
     *
     * @param \ReflectionMethod $method - Reflection-обертка для метода
     * @param array $args - массив аргументов
     *
     * @return array
     *
     * @throws AccessException
     * @throws ValidationException
     * @throws \Scaleplan\DTO\Exceptions\ValidationException
     */
    public static function sanitizeMethodArgs(\ReflectionMethod $method, array $args): array
    {
        $sanArgs = [];
        $docBlock = new DocBlock($method);
        [$docParams] = self::getDocParams($docBlock->getTagsByName('param'));
        if ($method->getNumberOfParameters() === 1) {
            $typeName = $method->getParameters()[0]->getType()->getName();
            if (is_subclass_of($typeName, DTO::class)) {
                /** @var DTO $arg */
                $arg = new $typeName($args);
                $arg->validate();
                $sanArgs[] = $arg;
                return $sanArgs;
            }
        }

        foreach ($method->getParameters() as &$param) {
            $paramName = $param->getName();
            $paramType = $param->getType()
                ? $param->getType()->getName() : ($docParams[$paramName] ? $docParams[$paramName]->getType() : '');

            if ($param->isVariadic()) {
                if (!$paramType) {
                    $sanArgs = array_merge($sanArgs, [$paramName => array_diff_key($args, $sanArgs)]);
                    break;
                }

                $sanArgs = array_merge($sanArgs, array_map(function ($arg) use ($paramType, $paramName, $docBlock) {
                    self::docTypeCheck($arg, $paramName, $paramType, $docBlock);

                    return $arg;
                }, $args));
                break;
            }

            if (!array_key_exists($paramName, $args) && !$param->isOptional()) {
                throw new ValidationException("Отсутствует необходимый параметр $paramName");
            }

            if ($param->isOptional()
                && (!array_key_exists($paramName, $args)
                    || ($args[$paramName] == $param->getDefaultValue() && $param->getDefaultValue() === null))
            ) {
                $sanArgs[$paramName] = $param->getDefaultValue();
                continue;
            }

            $arg = $args[$paramName];

            self::docTypeCheck($arg, $paramName, $paramType, $docBlock);

            \is_string($arg) && $arg = strip_tags($arg);

            $sanArgs[$paramName] = $arg;
        }

        return $sanArgs;
    }

    /**
     * Проверить аргументы для свойства-метода
     *
     * @param \ReflectionProperty $property - Reflection-обертка для SQL-свойства
     * @param array $args - массив аргументов
     *
     * @return array
     *
     * @throws AccessException
     */
    public static function sanitizeSQLPropertyArgs(\ReflectionProperty $property, array $args): array
    {
        $sanArgs = [];
        if (!$property->isPublic()) {
            $property->setAccessible(true);
        }

        $docBlock = new DocBlock($property);
        $docParams = $docBlock->getTagsByName('param');
        if ($docParams) {
            [$allParams, $optionParams] = self::getDocParams($docParams);
        } else {
            $value = $property->getDeclaringClass()->getDefaultProperties()[$property->getName()];
            $sqlParams = self::getSQLParams($value);
            $allParams = array_fill_keys($sqlParams[0], null);
            $optionParams = array_fill_keys($sqlParams[1], null);
        }

        foreach ($allParams as $paramName => $param) {
            self::argAvailabilityCheck($paramName, $args, $optionParams);

            if (!array_key_exists($paramName, $args)) {
                continue;
            }

            $arg = $args[$paramName];

            if ($param instanceof DocBlock\Tag && $param->getType()) {
                self::docTypeCheck($arg, $paramName, (string) $param->getType(), $docBlock);
            }

            \is_string($arg) && $arg = strip_tags($arg);

            $sanArgs[$paramName] = $arg;
        }

        return $sanArgs;
    }

    /**
     * Проверка наличия аргументов
     *
     * @param string $paramName - имя параметра
     * @param array $args - массив аргументов
     * @param array $optionParams - массив опциональных параметров
     *
     * @throws AccessException
     */
    protected static function argAvailabilityCheck(string $paramName, array $args, array $optionParams): void
    {
        if (!array_key_exists($paramName, $args) && !array_key_exists($paramName, $optionParams)) {
            throw new ValidationException("Не хватает параметра $paramName");
        }
    }

    /**
     * Вернуть массив DOCBLOCK-параметров и подгруппу необязательных параметров
     *
     * @param array $docParams - исходный массив параметров
     *
     * @return array
     */
    protected static function getDocParams(array $docParams): array
    {
        $allParams = $optionParams = [];
        foreach ($docParams as $docParam) {
            $varName = ltrim($docParam->getVariableName(), '$');
            $allParams[$varName] = $docParam;
            $paramDescription = (string) $docParam->getDescription();
            if ($paramDescription && stripos($paramDescription, '(optional)') !== false) {
                $optionParams[$varName] = $docParam;
            }
        }

        return [$allParams, $optionParams];
    }

    /**
     * Проверка аргументов на соответствие типу
     *
     * @param $arg - значение аргумента
     * @param string $paramName - имя аргумента/параметра
     * @param string $paramType - требуемый тип или группа типов
     * @param DocBlock $docBlock - ссылка объект DOCBLOCK метода или свойства
     *
     * @throws AccessException
     */
    protected static function docTypeCheck(&$arg, string $paramName, string $paramType, DocBlock $docBlock): void
    {
        if (!$paramType) {
            return;
        }

        $denyFuzzy = $docBlock->hasTag(Access::create()->getConfig(ConfigConstants::DOCBLOCK_CHECK_LABEL_NAME));

        $paramTypes = array_map(function ($item) {
            return trim($item, '\\\ \0');
        }, explode('|', $paramType));

        if (!self::typeCheck($arg, $paramTypes, $denyFuzzy)) {
            throw new ValidationException(
                "Тип параметра $paramName не соответствует заданному типу $paramType"
            );
        }
    }

    /**
     * Проверка значения на соответствие типу
     *
     * @param $value - значение
     * @param array $types - принимаемые типы
     * @param bool $denyFuzzy - строгое ли сравнение используется
     *
     * @return bool
     */
    public static function typeCheck(&$value, array $types, $denyFuzzy = true): bool
    {
        if (!$types && \in_array('mixed', $types, true)) {
            return true;
        }

        $argType = \gettype($value);
        if ($argType === 'object' && !array_filter($types, function ($type) use ($value, $denyFuzzy, $argType) {
                $result = $value instanceof $type;
                if (!$result && !$denyFuzzy) {
                    $result = preg_match("/^(\w+\\)*$type$/", $argType);
                }

                return $result;
        })) {
            return false;
        }

        if ($denyFuzzy) {
            return false;
        }

        foreach ($types as $type) {
            $tmp = $value;
            settype($tmp, $type);
            if ($tmp == $value) {
                $value = $tmp;
                return true;
            }
        }

        return false;
    }

    /**
     * Получить из SQL-запроса все параметры
     *
     * @param $sql
     *
     * @return array
     */
    public static function getSQLParams($sql): array
    {
        $all = $optional = [];
        if (preg_match_all('/[^:]+?:([\w_\-]+).*?/i', $sql, $matches)) {
            $all = array_unique($matches[1]);
        }

        if (preg_match_all('/\[[^\]]*?:([\w_\-]+)[^\[]*?\]/i', $sql, $matches)) {
            $optional = array_unique($matches[1]);
        }

        return [$all, $optional];
    }
}