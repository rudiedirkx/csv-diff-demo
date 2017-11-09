<?php

class Differ
{
    static public $colBsn = -1;
    static public $colSchool = -1;
    static public $colStart = -1;

    public $oldFp;
    public $newFp;

    public $oldHeader = [];
    public $newHeader = [];

    public $removedRows = [];
    public $addedRows = [];

    public function __construct($oldFile, $newFile)
    {
        $this->oldFp = fopen($oldFile, 'r');
        $this->newFp = fopen($newFile, 'r');

        $this->fetchHeaders();
        $this->fetchRows();
        $this->closePointers();
    }

    /** @return PlanDiff[] */
    public function getPlans()
    {
        $grouped = self::groupRowsBy($this->removedRows, $this->addedRows, 'bsn_school');

        return array_map(function (array $group) {
            return new PlanDiff($group);
        }, $grouped);
    }

    /** @return Row[] */
    public function getChanges()
    {
        $plans = $this->getPlans();

        return array_reduce($plans, function (array $changes, PlanDiff $plan) {
            return array_merge($changes, $plan->getChanges());
        }, []);
    }

    protected function fetchHeaders()
    {
        $this->oldHeader = $this->header($this->oldFp);
        $this->newHeader = $this->header($this->newFp);

        if ($this->oldHeader !== $this->newHeader) {
            throw new InvalidArgumentException('Header mismatch');
        }
    }

    protected function fetchRows()
    {
        $oldRows = $this->lines($this->oldFp);
        $newRows = $this->lines($this->newFp);

        $addedRows = array_diff($newRows, $oldRows);
        $removedRows = array_diff($oldRows, $newRows);

        $this->addedRows = array_map([$this, 'str2row'], $addedRows);
        $this->removedRows = array_map([$this, 'str2row'], $removedRows);
    }

    /** @return array */
    protected function header($fp)
    {
        return fgetcsv($fp, 0, ';');
    }

    /** @return string[] */
    protected function lines($fp)
    {
        $lines = [];
        while ($data = fgetcsv($fp, 0, ';')) {
            $lines[] = $data;
        }

        $lines = array_filter($lines);
        array_shift($lines);

        $lines = array_map('json_encode', $lines);
        return $lines;
    }

    protected function closePointers()
    {
        fclose($this->oldFp);
        fclose($this->newFp);
        $this->oldFp = $this->newFp = null;
    }

    /** @return Row */
    protected function str2row($line)
    {
        return new Row(json_decode($line, true));
    }

    static public function setBsnSchoolStart($colBsn, $colSchool, $colStart)
    {
        static::$colBsn = $colBsn;
        static::$colSchool = $colSchool;
        static::$colStart = $colStart;
    }

    /** @return Row[][][] */
    static public function groupRowsBy(array $removed, array $added, $col)
    {
        $grouped = [];
        foreach (['-' => $removed, '+' => $added] as $change => $rows) {
            /** @var Row[] $rows */
            foreach ($rows as $row) {
                $grouped[$row->$col][$change][] = $row;
            }
        }
        ksort($grouped);

        return $grouped;
    }
}

/**
 * @property string $bsn
 * @property string $school
 * @property string $bsn_school
 * @property string $start
 */
class Row
{
    public $data = [];

    /**
     * @param array $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /** @return int[] */
    public function diff(Row $other)
    {
        return array_keys(array_filter($other->data, function ($value, $col) {
            return $value !== $this->data[$col];
        }, ARRAY_FILTER_USE_BOTH));
    }

    protected function get_bsn()
    {
        return $this->data[Differ::$colBsn];
    }

    protected function get_school()
    {
        return $this->data[Differ::$colSchool];
    }

    protected function get_bsn_school()
    {
        return "$this->bsn-$this->school";
    }

    protected function get_start()
    {
        return $this->data[Differ::$colStart];
    }

    public function __get($name)
    {
        return call_user_func([$this, 'get_' . $name]);
    }
}

class RowChange extends Row
{
    public $type = '';
    public $changes = [];

    /**
     * @param string $type
     * @param array $data
     * @param array $changes
     */
    public function __construct($type, array $data, array $changes = [])
    {
        parent::__construct($data);

        $this->type = $type;
        $this->changes = $changes;
    }

    /** @return int[] */
    public function getChangedCols()
    {
        return $this->changes;
    }
}

class PlanDiff
{
    /** @var Row[] */
    public $old = [];
    /** @var Row[] */
    public $new = [];

    public function __construct($diff)
    {
        isset($diff['-']) and $this->old = array_values($diff['-']);
        isset($diff['+']) and $this->new = array_values($diff['+']);
    }

    /** @return Row[] */
    public function getChanges($step = 'outer')
    {
        if ($this->isAddition()) {
            return array_map(function (Row $row) {
                return new RowChange('added', $row->data);
            }, $this->new);
        }

        if ($this->isRemoval()) {
            return array_map(function (Row $row) {
                return new RowChange('removed', $row->data);
            }, $this->old);
        }

        $this->sort($this->old, 'start');
        $this->sort($this->new, 'start');

        if (count($this->old) == count($this->new)) {
            $changes = [];
            foreach ($this->new as $i => $newRow) {
                $oldRow = $this->old[$i];
                $changes[] = new RowChange('changed', $newRow->data, $newRow->diff($oldRow));
            }
            return $changes;
        }

        if ($step == 'outer') {
            $grouped = $this->groupByStart();

            return array_reduce($grouped, function (array $changes, PlanDiff $plan) {
                return array_merge($changes, $plan->getChanges('start'));
            }, []);
        }

        if ($step == 'start') {
            $both = min(count($this->new), count($this->old));

            $changes = [];
            for ($i = 0; $i < $both; $i++) {
                $changes[] = new RowChange('changed', $this->new[$i]->data, $this->new[$i]->diff($this->old[$i]));
            }

            if (count($this->old) > count($this->new)) {
                foreach (array_slice($this->old, $both) as $row) {
                    $changes[] = new RowChange('removed', $row->data);
                }
            }
            else {
                foreach (array_slice($this->new, $both) as $row) {
                    $changes[] = new RowChange('added', $row->data);
                }
            }

            return $changes;
        }

        throw new InvalidArgumentException("No resolution found.");
    }

    protected function sort(array &$rows, $col)
    {
        usort($rows, function (Row $a, Row $b) use ($col) {
            return strcmp($a->$col, $b->$col);
        });
    }

    /** @return PlanDiff[] */
    protected function groupByStart()
    {
        $grouped = Differ::groupRowsBy($this->old, $this->new, 'start');

        return array_map(function ($group) {
            return new PlanDiff($group);
        }, $grouped);
    }

    /** @return bool */
    protected function isAddition()
    {
        return $this->new && !$this->old;
    }

    /** @return bool */
    protected function isRemoval()
    {
        return $this->old && !$this->new;
    }
}

Differ::setBsnSchoolStart(9, 6, 25);
$differ = new Differ('11-02-orig.csv', '11-07-orig.csv');
$changes = $differ->getChanges();

class TableRenderer
{
    public $differ;

    public function __construct(Differ $differ)
    {
        $this->differ = $differ;
    }

    /** @return string */
    protected function cols($type, $first, $second, array $line, array $hilite = [])
    {
        $html = '';
        $html .= "<tr>";
        $html .= "<$type>" . $first . "</$type>";
        $html .= "<$type>" . $second . "</$type>";
        foreach ($line as $col => $value) {
            $ctype = in_array($col, $hilite) ? 'th' : $type;
            $html .= "<$ctype>" . $value . "</$ctype>";
        }
        $html .= "</tr>";
        return $html;
    }

    /** @return string */
    protected function row(RowChange $row)
    {
        return $this->cols('td', $row->type, implode(', ', $row->getChangedCols()), $row->data, $row->getChangedCols());
    }

    /** @return string */
    public function table(array $changes)
    {
        $changes = $this->sortRows($changes);

        $html = '';
        $html .= '<table border="1">';
        $html .= $this->cols('th', '', '', $this->differ->oldHeader);
        $bsn = null;
        foreach ($changes as $row) {
            if ($bsn && $bsn !== $row->bsn) {
                $html .= '<tr><td colspan="99">&nbsp;</td></tr>';
            }
            $html .= $this->row($row);
            $bsn = $row->bsn;
        }
        $html .= '</table>';
        return $html;
    }

    /** @return RowChange[] */
    protected function sortRows(array $rows)
    {
        usort($rows, function (Row $a, Row $b) {
            if ($a->bsn !== $b->bsn) {
                return strcmp($a->bsn, $b->bsn);
            }

            return 0;
        });

        return $rows;
    }
}

?>
<style>
td, th {
    white-space: nowrap;
    text-align: left;
}
</style>
<?php

$renderer = new TableRenderer($differ);
echo $renderer->table($changes);
