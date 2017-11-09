<?php

const CSV_BEFORE = '11-02-orig.csv';
const CSV_AFTER = '11-07-orig.csv';

const COL_BSN = 9;
const COL_SCHOOL = 6;
const COL_START = 25;

class Differ
{
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

    /** @return Row[] */
    public function sortRows(array $rows)
    {
        usort($rows, function (Row $a, Row $b) {
            if ($a->bsn !== $b->bsn) {
                return strcmp($a->bsn, $b->bsn);
            }

            if ($a->weight !== $b->weight) {
                return $a->weight - $b->weight;
            }

            return 0;
        });

        return $rows;
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

    public $weight = 0;

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

    /** @return int[] */
    public function getChangedCols()
    {
        return [];
    }

    protected function get_bsn()
    {
        return $this->data[COL_BSN];
    }

    protected function get_school()
    {
        return $this->data[COL_SCHOOL];
    }

    protected function get_bsn_school()
    {
        return "$this->bsn-$this->school";
    }

    protected function get_start()
    {
        return $this->data[COL_START];
    }

    public function __get($name)
    {
        return call_user_func([$this, 'get_' . $name]);
    }
}

class AddedRow extends Row
{
    public $weight = 2;
}

class RemovedRow extends Row
{
    public $weight = 0;
}

class ChangedRow extends Row
{
    public $weight = 1;

    public $changes = [];

    public function __construct(array $data, array $changes)
    {
        parent::__construct($data);
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
                return new AddedRow($row->data);
            }, $this->new);
        }

        if ($this->isRemoval()) {
            return array_map(function (Row $row) {
                return new RemovedRow($row->data);
            }, $this->old);
        }

        $this->sort($this->old, 'start');
        $this->sort($this->new, 'start');

        if (count($this->old) == count($this->new)) {
            $changes = [];
            foreach ($this->new as $i => $newRow) {
                $oldRow = $this->old[$i];
                $changes[] = new ChangedRow($newRow->data, $newRow->diff($oldRow));
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
                $changes[] = new ChangedRow($this->new[$i]->data, $this->new[$i]->diff($this->old[$i]));
            }

            if (count($this->old) > count($this->new)) {
                foreach (array_slice($this->old, $both) as $row) {
                    $changes[] = new RemovedRow($row->data);
                }
            }
            else {
                foreach (array_slice($this->new, $both) as $row) {
                    $changes[] = new AddedRow($row->data);
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

$differ = new Differ(CSV_BEFORE, CSV_AFTER);
$changes = $differ->getChanges();
$changes = $differ->sortRows($changes);

class TableRenderer
{
    public $differ;

    public function __construct(Differ $differ)
    {
        $this->differ = $differ;
    }

    /** @return string */
    public function cols($type, $first, $second, array $line, array $hilite = [])
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
    public function row(Row $row)
    {
        return $this->cols('td', get_class($row), implode(', ', $row->getChangedCols()), $row->data, $row->getChangedCols());
    }

    /** @return string */
    public function table(array $changes)
    {
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
