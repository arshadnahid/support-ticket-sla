<?php

namespace App\Providers;

use App\Repositories\Auth\AuthInterfaces\AuthInterface;
use App\Repositories\Auth\AuthRepository;
use App\Repositories\Category\CategoryInterfaces\CategoryInterface;
use App\Repositories\Category\CategoryRepository;
use App\Repositories\Ticket\TicketInterfaces\TicketInterface;
use App\Repositories\Ticket\TicketRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{

    /**
     * Interface => implementation. To change storage, write a new class
     * for the interface and swap it here.
     */
    public array $bindings = [
        AuthInterface::class => AuthRepository::class,
        TicketInterface::class => TicketRepository::class,
        CategoryInterface::class => CategoryRepository::class,
    ];

    /**
     * Register services.
     */
    public function register(): void {}

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
