{{--
  @var list<array{label: string, url: string}> $crumbs
  @var string $current
--}}
<nav aria-label="breadcrumb">
    <ol class="breadcrumb small mb-2">
        @foreach ($crumbs as $crumb)
            <li class="breadcrumb-item"><a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a></li>
        @endforeach
        <li class="breadcrumb-item active" aria-current="page">{{ $current }}</li>
    </ol>
</nav>
