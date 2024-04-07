<?php

defined("DEBUG") or die("拒绝访问。");

class Data
{
    public static $SQL = [

        "CREATE TABLE" => "CREATE TABLE {%NAME%} (`id` INT NOT NULL AUTO_INCREMENT , PRIMARY KEY (`id`)) ENGINE = InnoDB",
        "SHOW TABLES" => "SHOW TABLES LIKE ?", "SHOW COLUMNS" => "SHOW COLUMNS FROM {%NAME%} LIKE ?",
        "ALTER TABLE" => "ALTER TABLE {%NAME%} ADD {%ITEM%} {%TYPE%}{%NUM%} NOT NULL",
        "INSERT INTO" => "INSERT INTO {%NAME%} (`id`, {%FIELD%}) VALUES (NULL, {%PLACEHOLDER%})",
        "SELECT" => "SELECT {%FIELD%} FROM {%NAME%} WHERE {%WHERE%}",
        "UPDATE" => "UPDATE {%NAME%} SET {%SET%} WHERE {%WHERE%}",
        "DELETE" => "DELETE FROM {%NAME%} WHERE {%WHERE%}",
    ];

    private static Data|null $instance = null;
    protected PDO $connect;
    private DataTable $dataTable;

    private function __construct()
    {
        try {
            $dsn = "mysql:host={$_SERVER['CONF']['data']['host']};dbname={$_SERVER['CONF']['data']['db']};charset={$_SERVER['CONF']['data']['charset']}";
            $this->connect = new PDO($dsn, $_SERVER['CONF']['data']['user'], $_SERVER['CONF']['data']['pass']);

            if ($this->connect->errorCode()) {
                die("数据库连接失败。错误代码: " . $this->connect->errorCode());
            }
        } catch (Exception $e) {
            die("数据库连接失败。{$e->getMessage()}");
        }
    }

    /**
     * 单例模式
     * @return Data
     */
    public static function GetInstance(): Data
    {
        if (empty(self::$instance)) {
            self::$instance = new Data();
        }
        return self::$instance;
    }

    /**
     * 获取数据表操作类
     * @param string $tableName 欲操作的表名称
     * @return DataTable 数据表操作类
     */
    public function DataTable(string $tableName): DataTable
    {
        if (!isset($this->dataTable)) {
            $this->dataTable = new DataTable($tableName);
        }
        return $this->dataTable;
    }

    /**
     * 执行SQL语句
     * @param string $sql sql语句
     * @param array $data 预处理数据组
     * @param array $softReplace 全文本替换数据组
     * @param array|null $result 返回值回调
     * @return bool
     */
    public function Execute(string $sql, array $data = [], array $softReplace = [], array &$result = null): bool
    {
        foreach ($softReplace as $item => $value) {
            $sql = str_replace("{%{$item}%}", $value, $sql);
        }

        $stmt = $_SERVER['Class_Data']->connect->prepare($sql, [PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY]);

        for ($i = 0; $i < count($data); $i++) {
            $tmp = $data[$i];
            $stmt->bindValue($i + 1, $tmp);
        }


        $result_bool = $stmt->execute($data);

        //$stmt->debugDumpParams();

        $result = $stmt->fetchAll();

        return $result_bool;

    }

    private function __clone()
    {
    }

}

/**
 * 数据行操作类
 */
class DataLine
{
    /**
     * @var string 当前操作的数据表名
     */
    private string $TableName;

    public function __construct($tableName)
    {
        $this->TableName = $tableName;
    }

    /**
     * 插入一行数据
     * @param array $data 数据数组，应当为键值对：["code"=>100,"msg"=>"值"]
     * @return bool
     */
    public function Add(array $data): bool
    {
        $field = "";
        $placeholder = "";
        $values = [];
        foreach ($data as $item => $value) {
            if ($field != null && $field != "") {
                $field .= ", ";
            }
            if ($placeholder != null && $placeholder != "") {
                $placeholder .= ", ";
            }
            $field .= $item;
            $placeholder .= "?";
            $values[] = $value;
        }

        $result = $_SERVER['Class_Data']->Execute(Data::$SQL['INSERT INTO'], $values, ["NAME" => $this->TableName, "FIELD" => $field, "PLACEHOLDER" => $placeholder]);

        if (is_array($result)) {
            return true;
        }

        if (is_bool($result)) {
            return $result;
        }

        return false;
    }

    public function Read(array|string $field, string $where, array $values): array|bool
    {
        $fields = "";
        if (is_array($field)) {
            foreach ($field as $value) {
                if ($fields != null && $fields != "") {
                    $fields .= ",";
                }
                $fields .= "`" . $value . "`";
            }
        } else {
            $fields = $field;
        }

        $result = [];
        $result_bool = $_SERVER['Class_Data']->Execute(Data::$SQL['SELECT'], $values, ["NAME" => $this->TableName, "FIELD" => $fields, "WHERE" => $where], $result);

        if (!$result_bool) return false;

        return $result;

    }

    public function Update(string $set, string $where, array $values): bool
    {
        $result = [];
        $result_bool = $_SERVER['Class_Data']->Execute(Data::$SQL['UPDATE'], $values, ["NAME" => $this->TableName, "SET" => $set, "WHERE" => $where], $result);
        if (is_bool($result_bool)) return $result_bool;
        return false;
    }

    public function Delete(string $where, array $values): bool
    {
        $result = [];
        $result_bool = $_SERVER['Class_Data']->Execute(Data::$SQL['DELETE'], $values, ["NAME" => $this->TableName, "WHERE" => $where], $result);
        if (is_bool($result_bool)) return $result_bool;
        return false;
    }

}

/**
 * 数据表操作类
 */
class DataTable
{
    /**
     * @var string 当前操作的数据表名
     */
    private string $TableName;
    private DataLine $dataLine;

    public function __construct($tableName)
    {
        $this->TableName = $tableName;
    }

    public function DataLine(): DataLine
    {
        if (isset($this->dataLine)) {
            $this->dataLine = new DataLine($this->TableName);
        }
        return $this->dataLine;
    }

    /**
     * 创建数据表
     * @return int 1:成功,-1:失败,0:存在
     */
    public function DataTableCreate(): int
    {
        if ($this->DataTableExists()) return 0;
        $_SERVER['Class_Data']->Execute(Data::$SQL['CREATE TABLE'], [], ["NAME" => $this->TableName]);
        if ($this->DataTableExists()) return 1;
        return -1;
    }

    /**
     * 数据表是否存在
     * @return bool 是否存在
     */
    public function DataTableExists(): bool
    {
        $result = [];
        $result_bool = $_SERVER['Class_Data']->Execute(Data::$SQL['SHOW TABLES'], [$this->TableName], [], $result);

        if ($result_bool === false) return false;
        if (count($result) == 0) return false;

        return true;
    }

    /**
     * 删除数据表
     * @return bool 是否成功
     */
    public function DataTableDelete(): bool
    {
        return false;
    }

    /**
     * 增加列到数据表
     * @param string $columnName 列名
     * @param string $type 类型 INT VARCHAR TEXT DATE
     * @param string $length 长度，两侧需要加括号，例如(10)
     * @return int 是否成功
     */
    public function DataTableColumnAdd(string $columnName, string $type, string $length): int
    {
        if ($this->DataTableColumnExist($columnName)) {
            return 0;
        }

        $result = [];
        $result_bool = $_SERVER['Class_Data']->Execute(Data::$SQL['ALTER TABLE'], [], ["DATA" => $_SERVER['CONF']['data']['db'], "NAME" => $this->TableName, "ITEM" => $columnName, "TYPE" => $type, "NUM" => $length], $result);

        if (!$result_bool) return -1;

        return 1;
    }

    /**
     * 列是否存在于数据表
     * @param string $columnName 列名
     * @return bool
     */
    public function DataTableColumnExist(string $columnName): bool
    {
        $result = [];
        $columnName = str_replace("`", "", $columnName);
        $result_bool = $_SERVER['Class_Data']->Execute(Data::$SQL['SHOW COLUMNS'], [$columnName], ["DATA" => $_SERVER['CONF']['data']['db'], "NAME" => $this->TableName], $result);
        if ($result_bool === false || count($result) == 0) return false;
        return true;
    }

    /**
     * 删除列于数据表
     * @param string $columnName 列名
     * @return bool 是否成功
     */
    public function DataTableColumnDelete(string $columnName): bool
    {
        return false;
    }
}