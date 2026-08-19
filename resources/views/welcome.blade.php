<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'Laravel') }}</title>

        @vite(['resources/css/app.css'])
    </head>
    <body class="flex min-h-screen flex-col items-center justify-center gap-6 bg-[#FDFDFC] p-6 text-center font-sans text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
        <p class="max-w-md text-lg">
            {{ config('app.name', 'Laravel') }} is a content pipeline for planning, scheduling, and publishing your posts and videos.
        </p>

        <nav class="flex items-center gap-4 text-sm">
            <a href="{{ route('login') }}" class="rounded-sm border border-[#19140035] px-5 py-1.5 hover:border-[#1915014a] dark:border-[#3E3E3A] dark:hover:border-[#62605b]">
                Log in
            </a>
            <a href="{{ route('register') }}" class="rounded-sm border border-[#19140035] px-5 py-1.5 hover:border-[#1915014a] dark:border-[#3E3E3A] dark:hover:border-[#62605b]">
                Sign up
            </a>
        </nav>
    </body>
</html>
