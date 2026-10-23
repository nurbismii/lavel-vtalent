@extends('emails.layout', [
    'title' => $subject,
    'preheader' => 'Informasi dari Tim HR VDNI untuk kandidat.',
    'eyebrow' => 'INFORMASI KANDIDAT',
    'name' => 'Bapak/Ibu Kandidat',
    'footerDescription' => 'Email ini berisi informasi terkait proses rekrutmen VDNI.',
    'footerHelp' => 'Untuk pertanyaan lebih lanjut, hubungi Tim HR melalui kanal komunikasi rekrutmen yang telah diberikan.',
])

@section('content')
    <div style="overflow-wrap:anywhere;">{!! str($body)->sanitizeHtml() !!}</div>
@endsection
