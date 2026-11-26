<div class="container py-4">
    <h1>BlockSign-Status und Downloads</h1>
    {if $blocksignStatus !== ''}
        <p>{$blocksignStatus|escape:'html'}</p>
        <p><a href="{$blocksignOverview|escape:'html'}">Alle BlockSign-Bestellungen</a></p>
    {elseif count($blocksignOrders) === 0}
        <p>Es sind keine BlockSign-Bestellungen vorhanden.</p>
    {/if}
    {if count($blocksignOrders) > 0}
        <ul>
            {foreach $blocksignOrders as $order}
                <li><a href="{$order.url|escape:'html'}">Bestellung {$order.number|escape:'html'}</a></li>
            {/foreach}
        </ul>
    {/if}
    {if count($blocksignDownloads) > 0}
        <ul>
            {foreach $blocksignDownloads as $file}
                <li><a href="{$file.url|escape:'html'}">{$file.name|escape:'html'}</a></li>
            {/foreach}
        </ul>
    {/if}
</div>
