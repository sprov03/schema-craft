{!! $phpOpenTag !!}

namespace App;

class {!! $class_name !!}
{
@foreach ($schema->columns as $column)
    public {!! $column->phpType() !!} ${!! $column->name !!};
@endforeach
}
