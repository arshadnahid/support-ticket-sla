<?php

namespace App\DTOs\Auth;

class RegisterDTO
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $password
    ) {}

    public function toArray(): array
    {
        return [
            'name'      =>  $this->name,
            'email'     =>  $this->email,
            'password'  =>  $this->password
        ];
    }
}
