<?php
/**
 * Класс AmoObject. Абстрактный базовый класс для работы с сущностями amoCRM.
 *
 * @author    andrey-tech
 * @copyright 2019-2020 andrey-tech
 * @see https://github.com/andrey-tech/amocrm-api
 * @license   MIT
 *
 * @version 1.5.0
 *
 * v1.0.0 (24.04.2019) Начальный релиз
 * v1.0.1 (09.08.2019) Добавлено 5 секунд к updated_at
 * v1.1.0 (19.08.2019) Добавлен метод delete()
 * v1.1.1 (13.11.2019) Добавлено исключение в метод fillById()
 * v1.2.0 (13.11.2019) Добавлен метод getCustomFieldValueById()
 * v1.2.1 (22.02.2020) Удален метод delete(), как более не поддерживаемый
 * v1.3.0 (10.05.2020) Добавлена проверка ответа сервера в метод save(). Добавлено свойство request_id
 * v1.4.0 (16.05.2020) Добавлена параметр $returnResponse в метод save()
 * v1.5.0 (19.05.2020) Свойство $subdomain теперь является публичным
 *
 */

declare(strict_types = 1);

namespace AmoCRM;

abstract class AmoObject
{
    /**
     * Путь для запроса к API (переопределяется в дочерних классах)
     * @var string
     */
    const URL = '';

    /**
     * Типы привязываемых элементов
     * @var constant
     */
    const CONTACT_TYPE = 1;
    const LEAD_TYPE = 2;
    const COMPANY_TYPE = 3;
    const TASK_TYPE = 4;
    const CUSTOMER_TYPE = 12;

    /**
     * @var int
     */
    public $id;

    /**
     * @var string
     */
    public $name;

    /**
     * @var int
     */
    public $responsible_user_id;

    /**
     * @var int
     */
    public $created_by;

    /**
     * @var int
     */
    public $updated_by;

    /**
     * @var int
     */
    public $created_at;

    /**
     * @var int
     */
    public $updated_at;

    /**
     * @var int
     */
    public $account_id;

    /**
     * @var array
     */
    public $custom_fields = [];

    /**
     * @var array
     */
    public $tags = [];

    /**
     * @var int
     */
    public $group_id;

    /**
     * @var int
     */
    public $request_id;

    /**
     * Текущий поддомен для доступа к API
     * @var string
     */
    public $subdomain;

    /**
     * Конструктор
     * @param array $data
     */
    public function __construct(array $data = [])
    {
        if (isset($data['subdomain'])) {
            $this->subdomain = $data['subdomain'];
            unset($data['subdomain']);
        }
        $this->fill($data);
    }

    /**
     * Заполняет модель значениями из массива data
     * @param array $data
     * @return void
     */
    protected function fill(array $data = [])
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    /**
     * Приводит модель к формату для передачи в API
     * @return array
     */
    public function getParams() :array
    {
        $params = [];
        $properties = [ 'id', 'name', 'responsible_user_id', 'created_by', 'created_at',
            'updated_by', 'account_id', 'group_id', 'request_id' ];
        foreach ($properties as $property) {
            if (isset($this->$property)) {
                $params[$property] = $this->$property;
            }
        }

        if (count($this->custom_fields)) {
            $params['custom_fields'] = $this->custom_fields;
        }
        
        if (count($this->tags)) {
            $params['tags'] = array_column($this->tags, 'name');
        }

        // Если обновление сущности, то добавляем обязательный параметр 'updated_at'.
        // Добавляем 5 секунд для снижения вероятности возниконвения ошибки в amoCRM:
        // "Last modified date is older than in database"
        if (isset($this->id)) {
            $params['updated_at'] = time() + 5;
        }

        return $params;
    }

    /**
     * Заполняет модель по id
     * @param int|string $id
     * @param array $params
     * @return AmoObject
     */
    public function fillById($id, array $params = []) :AmoObject
    {
        $params = array_merge([ 'id' => $id ], $params);
        $response = AmoAPI::request($this::URL, 'GET', $params, $this->subdomain);
        if (empty($response)) {
            $className = get_class($this);
            throw new AmoAPIException("Не найдена сущность {$className} с ID {$id}");
        }
        $this->fill($response['_embedded']['items'][0]);
        return $this;
    }

    /**
     * Возвращает значение дополнительного поля по его ID
     * @param  int|string $id ID дополнительного поля
     * @return mixed
     */
    public function getCustomFieldValueById($id)
    {
        $index = array_search($id, array_column($this->custom_fields, 'id'));
        if ($index === false) {
            return null;
        }

        $value = array_shift($this->custom_fields[$index]['values']);
        $value = $value['value'] ?? null;

        return $value;
    }

    /**
     * Возвращает массив дополнительных полей по их id
     * @param array|int $ids
     * @return array
     *
     */
    public function getCustomFields($ids)
    {
        if (! is_array($ids)) {
            $ids = [ $ids ];
        }
        
        return array_intersect_key(
            $this->custom_fields,
            array_intersect(
                array_column($this->custom_fields, 'id'),
                $ids
            )
        );
    }

    /**
     * Устанавливет значение кастомным полям
     * @param array $params = [ 'id' => '12345678' ]
     * @return AmoObject
     */
    public function setCustomFields(array $params)
    {
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $field = [
                    'id' => $key,
                    'values' => $value
                ];
            } else {
                $field = [
                    'id' => $key,
                    'values' => [
                        [ 'value' => $value ]
                    ]
                ];
            }

            $i = array_search($key, array_column($this->custom_fields, 'id'));
            if ($i !== false) {
                $this->custom_fields[$i]['values'] = $field['values'];
            } else {
                $this->custom_fields[] = $field;
            }
        }

        return $this;
    }

    /**
     * Добавляет тэги
     * @param array | string $tags
     * @return AmoObject
     *
     */
    public function addTags($tags) :AmoObject
    {
        if (! is_array($tags)) {
            $tags = [ $tags ];
        }

        foreach ($tags as $value) {
            $tag = [
                'name' => $value
            ];

            if (! in_array($value, array_column($this->tags, 'name'))) {
                $this->tags[] = $tag;
            }
        }
    
        return $this;
    }

    /**
     * Удаляет тэги
     * @param array | string $tags
     * @return AmoObject
     *
     */
    public function delTags($tags) :AmoObject
    {
        if (! is_array($tags)) {
            $tags = [ $tags ];
        }
        $this->tags = array_diff_key($this->tags, array_intersect(array_column($this->tags, 'name'), $tags));

        return $this;
    }

    /**
     * Обновляет или добавляет объект в amoCRM
     * @param  bool $returnResponse Вернуть ответ сервера вместо ID сущности
     * @return array|int
     *
     */
    public function save(bool $returnResponse = false)
    {
        if (isset($this->id)) {
            $params = [ 'update' => [ $this->getParams() ] ];
        } else {
            $params = [ 'add' => [ $this->getParams() ] ];
        }

        $response = AmoAPI::request($this::URL, 'POST', $params, $this->subdomain);

        if (empty($response)) {
            $action = isset($this->id) ? 'обновить' : 'добавить';
            $className = get_class($this);
            $message = "Не удалось {$action} {$className} (пустой ответ): " . print_r($params, true);
            throw new AmoAPIException($message);
        }

        if (! $returnResponse) {
            return $response['_embedded']['items'][0]['id'];
        }

        return $response;
    }
}
