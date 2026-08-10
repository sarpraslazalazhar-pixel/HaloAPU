<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'user_id',
        'admin_one_id',
        'admin_two_id',
        'last_message_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'admin_one_id' => 'integer',
        'admin_two_id' => 'integer',
        'last_message_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function adminOne()
    {
        return $this->belongsTo(Admin::class, 'admin_one_id');
    }

    public function adminTwo()
    {
        return $this->belongsTo(Admin::class, 'admin_two_id');
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }
}
