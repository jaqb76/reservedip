// resources/views/ip_addresses/index.blade.php

@extends('layouts.app')

@section('content')
    <h1>IP Addresses</h1>
    <table class="table">
        <thead>
            <tr>
                <th>Address</th>
                <th>Reserved</th>
                <th>Description</th>
                <th>User</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($ipAddresses as $ipAddress)
                <tr>
                    <td>{{ $ipAddress->address }}</td>
                    <td>{{ $ipAddress->reserved }}</td>
                    <td>{{ $ipAddress->description }}</td>
                    <td>{{ $ipAddress->user->name }}</td>
                    <td>
                        <a href="{{ route('ip_addresses.edit', $ipAddress->id) }}">Edit</a>
                        <a href="{{ route('ip_addresses.destroy', $ipAddress->id) }}">Delete</a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
