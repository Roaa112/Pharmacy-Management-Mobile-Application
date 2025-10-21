@extends('adminlte::page')

@section('title', 'Contact Messages')

@section('content_header')
    <h1>Contact Messages</h1>
@stop

@section('content')
    <div class="card">
        <div class="card-header">
            <div class="row">
                <div class="col-md-6">
                    <h3 class="card-title">Messages List</h3>
                </div>
               
            </div>
        </div>
        <div class="card-body">
            @if(count($ContactMessages) > 0)
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead class="thead-dark">
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Message</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($ContactMessages as $message)
                                <tr>
                                    <td>{{ $message->id }}</td>
                                    <td>{{ $message->name }}</td>
                                    <td>{{ $message->email }}</td>
                                    <td>{{ Str::limit($message->message, 70) }}</td>
                                    <td>{{ $message->created_at->format('Y-m-d H:i') }}</td>
                                    <td style="width: 150px;">
                                        <div class="btn-group">
                                            
                                        <form action="{{ route('dashboard.contact-messages.destroy', $message->id) }}" 
      method="POST" class="d-inline">
    @csrf
    @method('DELETE')
    <button type="submit" class="btn btn-danger btn-sm" 
            title="Delete" onclick="return confirm('Are you sure?')">
        <i class="fas fa-trash"></i>
    </button>
</form>

                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

               
            @else
                <div class="alert alert-info">
                    No contact messages found.
                </div>
            @endif
        </div>
    </div>
@stop

