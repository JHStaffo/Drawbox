<form class="card protocol-search" method="get" action="{{ route('protocols.index') }}">
    <div class="field-row" style="align-items:flex-end">
        <div class="field" style="grid-column: span 2">
            <label for="q">Search the book</label>
            <input id="q" type="search" name="q" value="{{ $filters['q'] }}" placeholder="e.g. epinephrine, torsades, stroke">
        </div>
        <div class="field">
            <label for="category">Category</label>
            <select id="category" name="category">
                <option value="">All categories</option>
                @foreach ($categories as $c)<option value="{{ $c->id }}" @selected($filters['category'] === $c->id)>{{ $c->name }}</option>@endforeach
            </select>
        </div>
        <div class="field">
            <label for="age">Age</label>
            <select id="age" name="age">
                <option value="">All ages</option>
                @foreach (\App\Models\ProtocolVersion::AGES as $key => $label)<option value="{{ $key }}" @selected($filters['age'] === $key)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div class="field"><button class="btn btn-block" type="submit">Search</button></div>
    </div>
</form>
