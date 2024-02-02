<?php

namespace app\libraries;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use PHPSQLParser\PHPSQLParser;

class KMGDatatables
{
    protected $ciVersion;
    protected $config;

    protected $request;
    protected $queryBuilder;
    protected $columnAliases = [];
    protected $fieldNames = [];
    protected $returnedFieldNames = [];
    protected $formatters = [];
    protected $extraColumns = [];

    protected $recordsTotal;
    protected $recordsFiltered;

    protected $only = [];
    protected $except = [];

    protected $sequenceNumber = FALSE;
    protected $sequenceNumberKey;

    protected $asObject = FALSE;

    /**
     * The class constuctor
     * @param $queryBuilder
     * @param string|int $ciVersion The codeIgniter version to use
     */
    public function __construct($queryBuilder, $ciVersion = '4')
    {
        $this->ciVersion = $ciVersion;
        // $this->config = new Config($ciVersion);
        $this->request = Request::createFromGlobals();
        $this->queryBuilder = $queryBuilder;

        $replection = new \ReflectionProperty($queryBuilder, $this->_config_get('QBSelect'));
        $replection->setAccessible(TRUE);

        $qbSelect = $replection->getValue($queryBuilder);
        $this->columnAliases = $this::getColumnAliases($qbSelect);

        // When getting the field names, the query builder will be changed
        // So we need to make a clone to keep the original
        $queryBuilderClone = clone $queryBuilder;
        $this->fieldNames = $this::getFieldNames($queryBuilderClone, $this->config);

        $this->recordsTotal = $this->queryBuilder->{$this->_config_get('countAllResults')}('', FALSE);
    }

    /**
     * Format the value of spesific key
     * @param string $key The key to formatted
     * @param function $callback The formatter callback
     *
     * @return $this
     */
    public function format($key, $callback)
    {
        $this->formatters[$key] = $callback;
        return $this;
    }

    /**
     * Add extra column
     * @param string $key The key of the column
     * @param $callback The extra column callback, like a formatter
     *
     * @return $this
     */
    public function addColumn($key, $callback)
    {
        $this->extraColumns[$key] = $callback;
        return $this;
    }

    /**
     * Add column alias
     * Very useful when using SELECT JOIN to prevent column ambiguous
     * @param string $key The key of the column name (e.g. including the table name: posts.id or p.id)
     * @param string $alias The column alias
     *
     * @return $this
     */
    public function addColumnAlias($key, $alias)
    {
        $this->columnAliases[$alias] = $key;
        return $this;
    }

    /**
     * Add multiple column alias
     * @param array $aliases The column aliases with key => value format
     *
     * @return $this
     */
    public function addColumnAliases($aliases)
    {
        if ( ! is_array($aliases)) {
            throw new \Exception('The $aliases parameter must be an array.');
        }

        foreach ($aliases as $key => $alias) {
            $this->addColumnAlias($key, $alias);
        }

        return $this;
    }

    /**
     * Only return the column as defined
     * @param string|array $columns The columns to only will be returned
     *
     * @return $this
     */
    public function only($columns)
    {
        if (is_array($columns)) {
            array_push($this->only, ...$columns);
        } else {
            array_push($this->only, $columns);
        }
        return $this;
    }

    /**
     * Return all column except this
     * @param string|array $columns The columns to except
     *
     * @return $this
     */
    public function except($columns)
    {
        if (is_array($columns)) {
            array_push($this->except, ...$columns);
        } else {
            array_push($this->except, $columns);
        }
        return $this;
    }

    /**
     * Set the returned field names base on only & except
     * We will use the only first if defined
     * So you must use either only or except (not both)
     */
    protected function setReturnedFieldNames()
    {
        if ( ! empty($this->only)) {
            // Keep fields order as defined
            foreach ($this->only as $field) {
                if (in_array($field, $this->fieldNames)) {
                    array_push($this->returnedFieldNames, $field);
                }
            }
        } elseif ( ! empty($this->except)) {
            foreach ($this->fieldNames as $field) {
                if ( ! in_array($field, $this->except)) {
                    array_push($this->returnedFieldNames, $field);
                }
            }
        } else {
            $this->returnedFieldNames = $this->fieldNames;
        }
    }

    /**
     * Add sequence number to the output
     * @param string $key Used when returning object output as the key
     */
    public function addSequenceNumber($key = 'sequenceNumber')
    {
        $this->sequenceNumber = TRUE;
        $this->sequenceNumberKey = $key;
        return $this;
    }

    /**
     * Run the filter query both for global & individual filter
     */
    protected function filter()
    {
        $globalSearch = [];
        $columnSearch = [];

        $fieldNamesLength = count($this->returnedFieldNames);

        // Global column filtering
        if ($this->request->get('search') && ($keyword = $this->request->get('search')['value']) != '') {
            foreach ($this->request->get('columns', []) as $request_column) {
                if (filter_var($request_column['searchable'], FILTER_VALIDATE_BOOLEAN)) {
                    $column = $request_column['data'];

                    if ( ! $this->asObject) {
                        // Skip sequence number
                        if ($this->sequenceNumber && $column == 0) continue;

                        $fieldIndex = $this->sequenceNumber ? $column - 1 : $column;

                        // Skip extra column
                        if ($fieldIndex > $fieldNamesLength - 1) break;

                        $column = $this->returnedFieldNames[$fieldIndex];
                    }

                    // Checking if it using a column alias
                    $column = isset($this->columnAliases[$column])
                                ? $this->columnAliases[$column]
                                : $column;

                    $globalSearch[] = sprintf("`%s` LIKE '%%%s%%'", $column, $keyword);
                }
            }
        }

        // Individual column filtering
        foreach ($this->request->get('columns', []) as $request_column) {
            if (
                filter_var($request_column['searchable'], FILTER_VALIDATE_BOOLEAN) &&
                ($keyword = $request_column['search']['value']) != ''
            ) {
                $column = $request_column['data'];

                if ( ! $this->asObject) {
                    // Skip sequence number
                    if ($this->sequenceNumber && $column == 0) continue;

                    $fieldIndex = $this->sequenceNumber ? $column - 1 : $column;

                    // Skip extra column
                    if ($fieldIndex > $fieldNamesLength - 1) break;

                    $column = $this->returnedFieldNames[$fieldIndex];
                }

                // Checking if it using a column alias
                $column = isset($this->columnAliases[$column])
                            ? $this->columnAliases[$column]
                            : $column;

                $columnSearch[] = sprintf("`%s` LIKE '%%%s%%'", $column, $keyword);
            }
        }

        // Merge global search & column search
        $w_filter = '';

        if ( ! empty($globalSearch)) {
            $w_filter = '(' . implode(' OR ', $globalSearch) . ')';
        }

        if ( ! empty($columnSearch)) {
            $w_filter = $w_filter === '' ?
                implode(' AND ', $columnSearch) :
                $w_filter . ' AND ' . implode(' AND ', $columnSearch);
        }

        if ($w_filter !== '') {
            $this->queryBuilder->{$this->_config_get('where')}($w_filter);
        }

        $this->recordsFiltered = $this->queryBuilder->{$this->_config_get('countAllResults')}('', FALSE);
    }

    /**
     * Run the order query
     */
    protected function order()
    {
        if ($this->request->get('order') && count($this->request->get('order'))) {
            $orders = [];
            $fieldNamesLength = count($this->returnedFieldNames);

            foreach ($this->request->get('order') as $order) {
                $column_idx = $order['column'];
                $request_column = $this->request->get('columns')[$column_idx];

                if (filter_var($request_column['orderable'], FILTER_VALIDATE_BOOLEAN)) {
                    $column = $request_column['data'];

                    if ( ! $this->asObject) {
                        // Skip sequence number
                        if ($this->sequenceNumber && $column == 0) continue;

                        $fieldIndex = $this->sequenceNumber ? $column - 1 : $column;

                        // Skip extra column
                        if ($fieldIndex > $fieldNamesLength - 1) break;

                        $column = $this->returnedFieldNames[$fieldIndex];
                    }

                    $orders[] = sprintf('`%s` %s', $column, strtoupper($order['dir']));
                }
            }

            if (!empty($orders)) {
                $this->queryBuilder->{$this->_config_get('orderBy')}(implode(', ', $orders));
            }
        }
    }

    /**
     * Run the limit query for paginating
     */
    protected function limit()
    {
        if (($start = $this->request->get('start')) !== NULL && ($length = $this->request->get('length')) != -1) {
            $this->queryBuilder->{$this->_config_get('limit')}($length, $start);
        }
    }

    /**
     * Define the result as objects instead of arrays
     *
     * @return $this
     */
    public function asObject()
    {
        $this->asObject = TRUE;
        return $this;
    }

    /**
     * Generate the datatables results
     */
    public function generate()
    {
        $this->setReturnedFieldNames();
        $this->filter();
        $this->order();
        $this->limit();

        $result = $this->queryBuilder->{$this->_config_get('get')}();

        $output = [];

        $sequenceNumber = $this->request->get('start') + 1;
        foreach ($result->{$this->_config_get('getResult')}() as $res) {
            // Add sequence number if needed
            if ($this->sequenceNumber) {
                $row[$this->sequenceNumberKey] = $sequenceNumber++;
            }

            foreach ($this->returnedFieldNames as $field) {
                $row[$field] = isset($this->formatters[$field])
                                ? $this->formatters[$field]($res->$field, $res)
                                : $res->$field;
            }

            // Add extra columns
            foreach ($this->extraColumns as $key => $callback) {
                $row[$key] = $callback($res);
            }

            if ($this->asObject) {
                $output[] = (object) $row;
            } else {
                $output[] = array_values($row);
            }
        }

        $response = new JsonResponse();
        $response->setData([
            'draw' => $this->request->get('draw'),
            'recordsTotal' => $this->recordsTotal,
            'recordsFiltered' => $this->recordsFiltered,
            'data' => $output
        ]);
        $response->send();
        exit;
    }

    private function _config_get($name)
    {
        $methodsMapping = [
            'countAllResults' => 'count_all_results',
            'orderBy' => 'order_by',
            'where' => 'where',
            'limit' => 'limit',
            'get' => 'get',
            'QBSelect' => 'qb_select',
            'getFieldNames' => 'list_fields',
            'getResult' => 'result',
            'getResultArray' => 'result_array',
        ];

        return $methodsMapping[$name];
    }

    private function getColumnAliases($qbSelect)
    {
        if (empty($qbSelect)) return [];

        $sql = 'SELECT '.implode(', ', $qbSelect);
        $parser = new PHPSQLParser();
        $parsed = $parser->parse($sql);

        $columnAliases = [];
        foreach ($parsed['SELECT'] as $select) {
            if ($select['alias']) {
                $alias = $select['alias']['name'];
                if ($select['expr_type'] == 'colref') {
                    $key = $select['base_expr'];
                } elseif (strpos($select['expr_type'], 'function') !== FALSE) {
                    $parts = [];
                    foreach ($select['sub_tree'] as $part) {
                        $parts[] = $part['base_expr'];
                    }
                    $key =  $select['base_expr'].'('.implode(', ', $parts).')';
                }
                $columnAliases[$alias] = $key;
            }
        }

        return $columnAliases;
    }

    /**
     * Get all select fields result
     * Used when we use the arrays data source for ordering
     * @param $queryBuilder
     * @param Config $config
     *
     * @return array
     */
    private function getFieldNames($queryBuilder, $config)
    {
        return $queryBuilder->where('0=1') // We don't need any data
                            ->{$this->_config_get('get')}()
                            ->{$this->_config_get('getFieldNames')}();
    }
}
