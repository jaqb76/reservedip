<!-- resources/views/vlans/index.blade.php -->

@extends('layouts.app')

@section('content')
    <h1>VLANs</h1>
    <table class="table">
        <thead>
            <tr>
                <th>VLAN Name</th>
                <th>Description</th>
                <th>Subnets</th>
                <th>IP Address Count</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($vlans as $vlan)
                <tr>
                    <td>{{ $vlan->name }}</td>
                    <td>{{ $vlan->description }}</td>
                    <td>{{ $vlan->subnets->count() }}</td>
                    <td>{{ $vlan->ipAddressCount }}</td>
                    <td>
                        <a href="{{ route('vlans.edit', $vlan->id) }}">Edit</a>
                        <a href="{{ route('vlans.destroy', $vlan->id) }}">Delete</a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <canvas id="vlan-usage-chart" width="400" height="200"></canvas>
    <script>
        var ctx = document.getElementById('vlan-usage-chart').getContext('2d');
        var chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: @json($vlans->pluck('name')),
                datasets: [{
                    label: 'IP Address Count',
                    data: @json($vlans->pluck('ipAddressCount')),
                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                scales: {
                    yAxes: [{
                        ticks: {
                            beginAtZero: true
                        }
                    }]
                }
            }
        });
    </script>
@endsection