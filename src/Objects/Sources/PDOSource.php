<?php

namespace DivineOmega\uxdm\Objects\Sources;

use DivineOmega\uxdm\Interfaces\SourceInterface;
use DivineOmega\uxdm\Objects\DataItem;
use DivineOmega\uxdm\Objects\DataRow;
use DivineOmega\uxdm\Objects\Sources\PDO\Join;
use Exception;
use PDO;
use PDOStatement;

class PDOSource implements SourceInterface
{
    private $pdo;
    private $tableName;
    private $fields = [];
    private $overrideSQL;
    private $joins = [];
    private $perPage = 10;

    public function __construct(PDO $pdo, $tableName)
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo = $pdo;
        $this->tableName = $tableName;
        $this->fields = $this->getTableFields();
    }

    private function getTableFields()
    {
        $sql = $this->getSQL(['*']);

        $stmt = $this->pdo->prepare($sql);
        $this->bindLimitParameters($stmt, 0, 1);

        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $tableFields = array_keys($row);

        return $tableFields;
    }

    public function addJoin(Join $join)
    {
        $this->joins[] = $join;
        $this->fields = $this->getTableFields();
    }

    public function setOverrideSQL($overrideSQL)
    {
        $selectString = 'SELECT ';

        if (stripos($overrideSQL, $selectString) === false) {
            throw new Exception('PDO Source Override SQL must contain \''.$selectString.'\' to select source data.');
        }

        $limitString = 'LIMIT ? , ?';

        if (stripos($overrideSQL, $limitString) === false) {
            throw new Exception('PDO Source Override SQL must contain \''.$limitString.'\' to allow for pagination of source data.');
        }

        $this->overrideSQL = $overrideSQL;
        $this->fields = $this->getTableFields();
    }

    public function setPerPage($perPage = 10)
    {
        $this->perPage = $perPage;
    }

    private function getSQL($fieldsToRetrieve)
    {
        $fieldsSQL = implode(', ', $fieldsToRetrieve);

        $sql = 'select '.$fieldsSQL.' from '.$this->tableName;

        foreach ($this->joins as $join) {
            $sql .= $join->getSQL();
        }

        $sql .= ' limit ? , ?';

        if ($this->overrideSQL) {
            $sql = $this->overrideSQL;
        }

        return $sql;
    }

    private function bindLimitParameters(PDOStatement $stmt, $offset)
    {
        $stmt->bindValue(1, $offset, PDO::PARAM_INT);
        $stmt->bindValue(2, $this->perPage, PDO::PARAM_INT);
    }

    public function getDataRows($page = 1, $fieldsToRetrieve = [])
    {
        $offset = (($page - 1) * $this->perPage);

        $sql = $this->getSQL($fieldsToRetrieve);

        $stmt = $this->pdo->prepare($sql);
        $this->bindLimitParameters($stmt, $offset);

        $stmt->execute();

        $dataRows = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $dataRow = new DataRow();

            foreach ($row as $key => $value) {
                $dataRow->addDataItem(new DataItem($key, $value));
            }

            $dataRows[] = $dataRow;
        }

        return $dataRows;
    }

    public function getFields()
    {
        return $this->fields;
    }

    public function countDataRows()
    {
        $sql = $this->getSQL([]);
        $fromPos = stripos($sql, 'from');
        $limitPos = strripos($sql, 'limit');
        $sqlSuffix = substr($sql, $fromPos, $limitPos - $fromPos);

        $sql = 'select count(*) as countDataRows '.$sqlSuffix;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row['countDataRows'];
    }

    public function countPages()
    {
        return ceil($this->countDataRows() / $this->perPage);
    }
}
