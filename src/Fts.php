<?php

namespace BootPress\SQLite;

class Fts
{
    /** @var object A BootPress\SQLite\Component instance. */
    private $db;

    /** @var bool Whether or not the SQL scalar function has been created. */
    private $rank;

    public function __construct(Component $db)
    {
        $this->db = $db;
    }

    /**
     * Create an SQLite FTS4 virtual table for fulltext searching.
     *
     * @param string $table    The database table name.
     * @param mixed  $fields   An ``array($field, ...)`` of names to create, or just a string (eg. '**search**') if there is only one field name.
     * @param string $tokenize Either '**simple**', or '**porter**' (the default).
     *
     * @return bool Either ``false`` if the table has already been created, or ``true`` if the table has been created anew.
     *
     * @example
     *
     * ```php
     * if ($db->created) {
     *
     *     $db->fts->create('results', 'search');
     *
     *     // You can insert, update, and query an FTS table the same as any other.
     *     if ($stmt = $db->insert('results', array('docid', 'search'))) {
     *         $db->insert($stmt, array(100, 'Fisherman never die, they just get reel tired.'));
     *         $db->insert($stmt, array(101, 'If wishes were fishes, we\'d have a fish fry.'));
     *         $db->insert($stmt, array(102, 'Women want me, fish fear me.'));
     *         $db->insert($stmt, array(103, 'Good things come to those who bait.'));
     *         $db->insert($stmt, array(104, 'A reel expert can tackle anything.'));
     *     }
     *
     * }
     * ```
     */
    public function create($table, array $fields, $tokenize = 'porter')
    {
        $fields = implode(', ', $fields);
        $query = "CREATE VIRTUAL TABLE {$table} USING fts4({$fields}, tokenize={$tokenize})";
        $executed = $this->db->info('tables', $table);
        if ($query == $executed) {
            return false; // the table has already been created
        }
        if (!is_null($executed)) {
            $this->db->exec('DROP TABLE '.$table);
        }
        $this->db->exec($query);
        $this->db->info('tables', $table, $query); // add or update
        return true; // the table has been created anew
    }

    /**
     * Get the total number of search results.
     *
     * @param string $table  The database table name.
     * @param string $search The search term(s) to '**MATCH**'.
     * @param string $where  An additional string of restrictions you would like to place. If you don't include '**WHERE**' we will add it for you. If you are combining tables to deliver results then put your '**INNER JOIN ... WHERE**' clause here, and prefix the search **$table** and fields with '**s.**' eg. ``INNER JOIN my_table AS my ON s.docid = my.id WHERE my.field = ...``
     *
     * @return int The total count.
     *
     * @example
     *
     * ```php
     * echo $db->fts->count('results', 'fish'); // 2
     * ```
     */
    public function count($table, $search, $where = '')
    {
        if (empty($where)) {
            $where = 'WHERE';
        } else {
            $where = (stripos($where, 'WHERE') === false) ? "WHERE {$where} AND" : "{$where} AND";
        }

        return $this->db->value("SELECT COUNT(*) FROM {$table} AS s {$where} s.{$table} MATCH ?", $search);
    }

    /**
     * Queries an FTS **$table** for the relevant **$search** word(s) found within.
     *
     * @param string $table   The database table name.
     * @param string $search  The search term(s) to '**MATCH**'.
     * @param mixed  $limit   If you are not paginating results and only want the top whatever, then this is an integer. Otherwise it is an SQL '<b> LIMIT offset, length</b>' clause.
     * @param string $where   An additional string of restrictions you would like to place. If you don't include '**WHERE**' we will add it for you. If you are combining tables to deliver results then put your '**INNER JOIN ... WHERE**' clause here, and prefix the search **$table** and fields with '**s.**' eg. ``INNER JOIN my_table AS my ON s.docid = my.id WHERE my.field = ...``
     * @param array  $fields  An ``array('s.field', ...)`` of additional fields you would like to include in the search results. Remember to specify the table prefixes if needed.
     * @param array  $weights An array of importance that you would like to place on the **$table** fields searched in whatever order you placed them originally. The default weights are 1 for each field, meaning they are all of equal importance. If you want to make one field more relevant than another, then make this an ``array($weight, ...)`` of importance to place on each corresponding **$table** field. Even if you place an importance of 0 on a field it will still be included among the search results, it will just have a lower rank (possibly 0). All of this assumes you have more than one field in your **$table**, otherwise this will make no difference whatsoever.
     *
     * @return array An associative array of results.
     *
     * @example
     *
     * ```php
     * var_export($db->fts->search('results', 'fish'));
     * array(
     *     array(
     *         'docid' => 101,
     *         'snippet' => "If wishes were <b>fishes</b>, we'd have a <b>fish</b> fry.",
     *         'offsets' => '0 0 15 6 0 0 35 4',
     *         'rank' => 1.333,
     *     ),
     *     array(
     *         'docid' => 102,
     *         'snippet' => 'Women want me, <b>fish</b> fear me.',
     *         'offsets' => '0 0 15 4',
     *         'rank' => .666,
     *     ),
     * );
     * ```
     */
    public function search($table, $search, $limit = '', $where = '', array $fields = array(), array $weights = array())
    {
        if (is_null($this->rank)) {
            $this->rank = $this->db->connection()->createFunction('rank', array(&$this, 'rank'), 2);
        }
        if (!empty($where)) {
            $where = (stripos($where, 'WHERE') === false) ? "WHERE {$where} AND" : "{$where} AND";
        } else {
            $where = 'WHERE';
        }
        $fields = (!empty($fields)) ? implode(', ', $fields).',' : '';
        $weights = "'".implode(',', $weights)."'"; // we pass this along to our rank function
        #-- Join, Order, Values --#
        $join = '';
        $order = 'rank';
        $values = array($search);
        if (!empty($limit)) {
            if (is_numeric($limit)) {
                $offset = 0;
                $length = $limit;
            } else {
                $limit = explode(',', preg_replace('/[^0-9,]/', '', $limit));
                $offset = (isset($limit[0])) ? (int) $limit[0] : 0;
                $length = (isset($limit[1])) ? (int) $limit[1] : 10;
            }
            $join = implode("\n", array(
                'JOIN (',
                "  SELECT s.docid, rank(matchinfo(s.{$table}), {$weights}) AS rank",
                "  FROM {$table} AS s {$where} s.{$table} MATCH ?",
                "  ORDER BY rank DESC LIMIT {$length} OFFSET {$offset}",
                ') AS ranktable USING (docid)',
            ));
            $order = 'ranktable.rank';
            $values[] = $search; // add one more to the MATCH
        }
        #-- Query --#
        $results = array();
        if ($stmt = $this->db->query(array(
            "SELECT s.docid, {$fields}",
            "  snippet(s.{$table}, '<b>', '</b>', '<b>...</b>', -1, 50) AS snippet,",
            "  offsets(s.{$table}) AS offsets,",
            "  rank(matchinfo(s.{$table}), {$weights}) AS rank",
            "FROM {$table} AS s {$join} {$where} s.{$table} MATCH ?",
            "ORDER BY {$order} DESC",
        ), $values, 'assoc')) {
            while ($row = $this->db->fetch($stmt)) {
                $results[] = $row;
            }
            $this->db->close($stmt);
        }

        return $results;
    }

    /**
     * Get the words that made your **$search** relevant for **$docid**.
     *
     * @param string $table  The database table name.
     * @param string $search The search term(s) to '**MATCH**'.
     * @param mixed  $docid  The **$table** row's docid.
     *
     * @return array The unique words found which made the **$search** relevant.
     *
     * @example
     *
     * ```php
     * echo implode(', ', $db->fts->words('results', 'fish', 101)); // fishes, fish
     * ```
     */
    public function words($table, $search, $docid)
    {
        $words = array();
        $search = $this->search($table, $search, 1, 's.docid = '.$docid);
        if (empty($search)) {
            return $words;
        }
        $row = array_shift($search);
        $fields = $this->db->row("SELECT * FROM {$table} WHERE docid = ? LIMIT 1", $row['docid'], 'assoc');

        return $this->offset(array_merge($fields, $row), array_keys($fields));
    }

    /**
     * Sorts through the **$row['offsets']** integers, and retrieves the words they reference.
     *
     * @param array $row    An associative array of each **$fields** value, including an '**offsets**' key.
     * @param array $fields An array of field names in the same order as they are found in the database search table.
     *
     * @return array The words that made this row relevant.
     *
     * @example
     *
     * ```php
     * print_r($db->fts->offset(array(
     *     'search' => "If wishes were fishes, we'd have a fish fry.",
     *     'offsets' => '0 0 15 6 0 0 35 4',
     * ), array('search'))); // array('fishes', 'fish');
     * ```
     *
     * @link https://www.sqlite.org/fts3.html#offsets
     */
    public function offset(array $row, array $fields)
    {
        $words = array();
        $search = array();
        foreach ($fields as $value) {
            $search[] = (isset($row[$value])) ? $row[$value] : '';
        }
        $offsets = explode(' ', $row['offsets']);
        $combine = array();
        for ($i = 0; $i < (count($offsets) / 4); ++$i) {
            list($column, $term, $byte, $size) = array_slice($offsets, $i * 4, 4);
            $word = strtolower(substr($search[$column], $byte, $size));
            if ($combine == array($column, $term, $byte)) {
                $word = array_pop($words).' '.$word;
            }
            $words[] = $word;
            $combine = array($column, $term + 1, $byte + $size + 1); // same column, next term, one space away
        }
        $words = array_unique($words);
        rsort($words);

        return $words;
    }

    /**
     * Ranks search results in order of relevance.  Used internally, and only made public because it has to be.
     * 
     * @param string $info
     * @param string $weights
     * 
     * @return float A relevancy rank.  A larger value indicates a more relevant result.
     * 
     * @link https://www.sqlite.org/fts3.html#appendix_a
     */
    public function rank($info, $weights)
    {
        if (!empty($weights)) {
            $weights = explode(',', $weights);
        }
        $score = (float) 0.0; // the value to return
        $isize = 4; // the amount of string we need to collect for each integer
        $phrases = (int) ord(substr($info, 0, $isize));
        $columns = (int) ord(substr($info, $isize, $isize));
        $string = $phrases.' '.$columns.' ';
        for ($p = 0; $p < $phrases; ++$p) {
            $term = substr($info, (2 + $p * $columns * 3) * $isize); // the start of $info for current phrase
            for ($c = 0; $c < $columns; ++$c) {
                $here = (float) ord(substr($term, (3 * $c * $isize), 1)); // total occurrences in this row and column
                $total = (float) ord(substr($term, (3 * $c + 1) * $isize, 1)); // total occurrences for all rows in this column
                $rows = (float) ord(substr($term, (3 * $c + 2) * $isize, 1)); // total rows with at least one occurence in this column
                $relevance = (!empty($total)) ? ($rows / $total) * $here : 0;
                $weight = (isset($weights[$c])) ? (float) $weights[$c] : 1;
                $score += $relevance * $weight;
                $string .= $here.$total.$rows.' ('.round($relevance, 2).'*'.$weight.') ';
            }
        }
        // return $string . '- ' . $score; // to debug
        return $score;
    }
}
