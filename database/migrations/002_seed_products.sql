INSERT INTO products (sku, name, type, price_minor, currency, image_path)
VALUES
    ('STEAM-TOPUP-500', 'Пополнение Steam 500 ₽', 'topup', 50000, 'RUB', 'assets/steam.png'),
    ('STEAM-TOPUP-1000', 'Пополнение Steam 1000 ₽', 'topup', 100000, 'RUB', 'assets/steam.png'),
    ('STEAM-TOPUP-2500', 'Пополнение Steam 2500 ₽', 'topup', 250000, 'RUB', 'assets/steam.png'),
    ('KEY-CS2-PRIME', 'CS2 Prime Status ключ', 'key', 129000, 'RUB', 'assets/cs2.png'),
    ('KEY-GTA5', 'GTA V ключ активации', 'key', 199000, 'RUB', 'assets/gta5.png'),
    ('KEY-EFT', 'Escape from Tarkov ключ', 'key', 349000, 'RUB', 'assets/eft.png'),
    ('SUB-DISCORD-1M', 'Discord Nitro 1 месяц', 'subscription', 39900, 'RUB', 'assets/discord.png'),
    ('SUB-YT-3M', 'YouTube Premium 3 месяца', 'subscription', 149000, 'RUB', 'assets/youtube.png'),
    ('SUB-SPOTIFY-1M', 'Spotify Premium 1 месяц', 'subscription', 29900, 'RUB', 'assets/spotify.png'),
    ('GIFT-PSN-1000', 'PlayStation Store карта 1000 ₽', 'giftcard', 100000, 'RUB', 'assets/psn.png'),
    ('GIFT-XBOX-1500', 'Xbox Gift Card 1500 ₽', 'giftcard', 150000, 'RUB', 'assets/xbox.png'),
    ('GIFT-ROBLOX-800', 'Roblox 800 Robux', 'giftcard', 89000, 'RUB', 'assets/roblox.png')
ON CONFLICT (sku) DO UPDATE SET
    name = EXCLUDED.name,
    type = EXCLUDED.type,
    price_minor = EXCLUDED.price_minor,
    currency = EXCLUDED.currency,
    image_path = EXCLUDED.image_path,
    is_active = TRUE,
    updated_at = NOW();
