@extends('admin.layouts.app')

@section('title', 'Admin Foundation Status')

@section('content')
    <section>
        <h1>{{ $appName }} — Custom Blade Admin Foundation</h1>
        <p>Status: Operational (API {{ $apiVersion }})</p>
    </section>
@endsection
