<?php

class Differ
{
    /**
     * @var VervoersplanGrouper
     */
    public $grouper;

    /**
     * @var resource
     */
    public $oldFp;

    /**
     * @var resource
     */
    public $newFp;

    /**
     * @var string[]
     */
    public $oldHeader = [];

    /**
     * @var string[]
     */
    public $newHeader = [];

    /**
     * @var Row[]
     */
    public $removedRows = [];

    /**
     * @var Row[]
     */
    public $addedRows = [];

    /**
     * @param string              $oldFile
     * @param string              $newFile
     * @param VervoersplanGrouper $grouper
     */
    public function __construct($oldFile, $newFile, VervoersplanGrouper $grouper)
    {
        $this->grouper = $grouper;

        $this->oldFp = fopen($oldFile, 'r');
        $this->newFp = fopen($newFile, 'r');

        $this->fetchHeaders();
        $this->fetchRows();
        $this->closePointers();
    }

    /**
     * @return PlanDiff[]
     */
    public function getPlans()
    {
        $grouped = self::groupRowsBy($this->removedRows, $this->addedRows, $this->grouper->makePlanGrouper());

        return array_map(function (array $group) {
            return new PlanDiff($group, $this->grouper);
        }, $grouped);
    }

    /**
     * @return Row[]
     */
    public function getChanges()
    {
        $plans = $this->getPlans();

        return array_reduce($plans, function (array $changes, PlanDiff $plan) {
            return array_merge($changes, $plan->getChanges());
        }, []);
    }

    /**
     * @return void
     */
    protected function fetchHeaders()
    {
        $this->oldHeader = $this->header($this->oldFp);
        $this->newHeader = $this->header($this->newFp);

        if ($this->oldHeader !== $this->newHeader) {
            throw new InvalidArgumentException('Header mismatch');
        }
    }

    /**
     * @return void
     */
    protected function fetchRows()
    {
        $oldRows = $this->lines($this->oldFp);
        $newRows = $this->lines($this->newFp);

        $addedRows = array_diff($newRows, $oldRows);
        $removedRows = array_diff($oldRows, $newRows);

        $this->addedRows = array_map([$this, 'str2row'], $addedRows);
        $this->removedRows = array_map([$this, 'str2row'], $removedRows);
    }

    /**
     * @param resource $fp
     * @return array
     */
    protected function header($fp)
    {
        return fgetcsv($fp, 0, ';');
    }

    /**
     * @param resource $fp
     * @return string[]
     */
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

    /**
     * @return void
     */
    protected function closePointers()
    {
        fclose($this->oldFp);
        fclose($this->newFp);
        $this->oldFp = $this->newFp = null;
    }

    /**
     * @param $line
     * @return Row
     */
    protected function str2row($line)
    {
        return new Row(json_decode($line, true));
    }

    /**
     * @param Row[]    $removed
     * @param Row[]    $added
     * @param callable $getValue
     * @return Row[][][]
     */
    static public function groupRowsBy(array $removed, array $added, callable $getValue)
    {
        $grouped = [];
        foreach (['-' => $removed, '+' => $added] as $change => $rows) {
            /** @var Row[] $rows */
            foreach ($rows as $row) {
                $grouped[ $getValue($row) ][$change][] = $row;
            }
        }
        ksort($grouped);

        return $grouped;
    }
}

class VervoersplanGrouper
{
    public $bsn = -1;
    public $school = -1;
    public $start = -1;

    /**
     * @param int $bsn
     * @param int $school
     * @param int $start
     */
    public function __construct($bsn, $school, $start)
    {
        $this->bsn = $bsn;
        $this->school = $school;
        $this->start = $start;
    }

    /**
     * @return Closure
     */
    public function makePlanGrouper()
    {
        return function (Row $row) {
            return $row->data[$this->bsn] . ' ' . $row->data[$this->school];
        };
    }

    /**
     * @return Closure
     */
    public function makeSorter()
    {
        return function (Row $row) {
            return $row->data[$this->start];
        };
    }

    /**
     * @return Closure
     */
    public function makeSubGrouper()
    {
        return function (Row $row) {
            return $row->data[$this->start];
        };
    }
}

class Row
{
    /**
     * @var array
     */
    public $data = [];

    /**
     * @param array $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @param Row $other
     * @return int[]
     */
    public function diff(Row $other)
    {
        return array_keys(array_filter($other->data, function ($value, $col) {
            return $value !== $this->data[$col];
        }, ARRAY_FILTER_USE_BOTH));
    }
}

class RowChange extends Row
{
    /**
     * @var string
     */
    public $type = '';

    /**
     * @var int[]
     */
    public $changes = [];

    /**
     * @param string $type
     * @param array  $data
     * @param array  $changes
     */
    public function __construct($type, array $data, array $changes = [])
    {
        parent::__construct($data);

        $this->type = $type;
        $this->changes = $changes;
    }

    /**
     * @return int[]
     */
    public function getChangedCols()
    {
        return $this->changes;
    }
}

class PlanDiff
{
    /**
     * @var VervoersplanGrouper
     */
    public $grouper;

    /**
     * @var Row[]
     */
    public $old = [];

    /**
     * @var Row[]
     */
    public $new = [];

    /**
     * @param array               $diff
     * @param VervoersplanGrouper $grouper
     */
    public function __construct($diff, VervoersplanGrouper $grouper)
    {
        $this->grouper = $grouper;

        isset($diff['-']) and $this->old = array_values($diff['-']);
        isset($diff['+']) and $this->new = array_values($diff['+']);
    }

    /**
     * @param string $step
     * @return Row[]
     */
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

        $getValue = $this->grouper->makeSorter();
        $this->old = $this->sort($this->old, $getValue);
        $this->new = $this->sort($this->new, $getValue);

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

    /**
     * @param Row[]    $rows
     * @param callable $getValue
     * @return Row[]
     */
    protected function sort(array $rows, callable $getValue)
    {
        usort($rows, function (Row $a, Row $b) use ($getValue) {
            return strcmp($getValue($a), $getValue($b));
        });

        return $rows;
    }

    /**
     * @return PlanDiff[]
     */
    protected function groupByStart()
    {
        $grouped = Differ::groupRowsBy($this->old, $this->new, $this->grouper->makeSubGrouper());

        return array_map(function ($group) {
            return new PlanDiff($group, $this->grouper);
        }, $grouped);
    }

    /**
     * @return bool
     */
    protected function isAddition()
    {
        return $this->new && !$this->old;
    }

    /**
     * @return bool
     */
    protected function isRemoval()
    {
        return $this->old && !$this->new;
    }
}

$differ = new Differ('11-02-orig.csv', '11-07-orig.csv', new VervoersplanGrouper(9, 6, 25));
$changes = $differ->getChanges();

class TableRenderer
{
    public $differ;

    public function __construct(Differ $differ)
    {
        $this->differ = $differ;
    }

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

    protected function row(RowChange $row)
    {
        return $this->cols('td', $row->type, implode(', ', $row->getChangedCols()), $row->data, $row->getChangedCols());
    }

    public function table(array $changes)
    {
        $changes = $this->sortRows($changes);

        $getValue = function (Row $row) {
            return $row->data[$this->differ->grouper->bsn];
        };
        $prevVal = null;

        $html = '';
        $html .= '<table border="1">';
        $html .= $this->cols('th', '', '', $this->differ->oldHeader);
        foreach ($changes as $row) {
            $newVal = $getValue($row);
            if ($prevVal && $prevVal !== $newVal) {
                $html .= '<tr><td colspan="99">&nbsp;</td></tr>';
            }
            $html .= $this->row($row);
            $prevVal = $newVal;
        }
        $html .= '</table>';
        return $html;
    }

    protected function sortRows(array $rows)
    {
        $getValue = $this->differ->grouper->makePlanGrouper();

        usort($rows, function (Row $a, Row $b) use ($getValue) {
            $aVal = $getValue($a);
            $bVal = $getValue($b);
            if ($aVal !== $bVal) {
                return strcmp($aVal, $bVal);
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
