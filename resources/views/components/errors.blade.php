@if($errors->any())<div class="errors" role="alert"><strong>Periksa kembali isian Anda.</strong>
    <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
</div>@endif