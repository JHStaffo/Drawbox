{{-- Rows for adding several brands or choices in one save. Blank rows are ignored. --}}
<div class="add-rows">
    <div class="table-wrap"><table>
        <tr><th>{{ ucfirst($noun) }}</th><th class="num">On hand</th></tr>
        @for ($i = 0; $i < 3; $i++)
            <tr class="add-row">
                <td><input type="text" name="items[{{ $i }}][brand]" placeholder="{{ $i === 0 ? $placeholder : '' }}" maxlength="100" aria-label="{{ ucfirst($noun) }}"></td>
                <td class="num"><input type="number" min="0" name="items[{{ $i }}][quantity]" value="0" style="width:70px" aria-label="On hand"></td>
            </tr>
        @endfor
    </table></div>
    <div class="row-actions mt">
        <button type="button" class="btn btn-small btn-outline add-row-btn" hidden>+ Another row</button>
        <button class="btn btn-small" type="submit">Add {{ $noun }}s</button>
    </div>
</div>
