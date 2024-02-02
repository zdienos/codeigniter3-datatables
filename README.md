## Simple Datatables Library just for Codeigniter 3


### Requirements

* Composer
* Codeigniter 3

### Installation

Just copy the file to libraries folder


### Example

```php
// CodeIgniter 3 Example

// Here we will select all fields from posts table
// and make a join with categories table
// Please note: we don't need to call ->get() here
$query = $this->db->select('p.*, c.name category')
                    ->from('posts p')
                    ->join('categories c', 'c.id=p.category_id');

$datatables = new datatables($queryBuilder);
$datatables->generate(); // done
```


### Some Others Options

```php
$datatables = new datatables($queryBuilder);

// Return the output as objects instead of arrays
$datatables->asObject();

// Only return title & category (accept string or array)
$datatables->only(['title', 'category']);

// Return all except the id
// You may use one of only or except
$datatables->except(['id']);

// Format the output
$datatables->format('title', function($value, $row) {
  return '<b>'.$value.'</b>';
});

// Add extra column
$datatables->addColumn('action', function($row) {
  return '<a href="url/to/delete/post/'.$row->id.'">Delete</a>';
});

// Add column alias
// It is very useful when we use SELECT JOIN to prevent column ambiguous
$datatables->addColumnAlias('p.id', 'id');

// Add column aliases
// Same as the addColumnAlias, but for multiple alias at once
$datatables->addColumnAliases([
  'p.id' => 'id',
  'c.name' => 'category'
]);

// Add squence number
// The default key is `sequenceNumber`
// You can change it with give the param
$datatables->addSequenceNumber();
$datatables->addSequenceNumber('rowNumber'); // It will be rowNumber

// Don't forget ot call generate to get the results
$datatables->generate();
```
