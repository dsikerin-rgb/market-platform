<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Market;

class MarketplaceDefaultSlideCatalog
{
    /**
     * @return list<array{
     *   title:string,
     *   badge:?string,
     *   description:string,
     *   theme:string,
     *   cta_label:?string,
     *   cta_url:?string,
     *   sort_order:int
     * }>
     */
    public static function defaultsForMarket(Market $market, array $settings = []): array
    {
        $marketSlug = filled($market->slug ?? null) ? (string) $market->slug : (string) $market->id;
        $catalogUrl = '/m/' . $marketSlug . '/catalog';
        $mapUrl = '/m/' . $marketSlug . '/map';
        $announcementsUrl = '/m/' . $marketSlug . '/announcements';

        $email = trim((string) ($settings['public_email'] ?? ''));
        $sellerMail = $email !== ''
            ? 'mailto:' . $email . '?subject=' . rawurlencode('Заявка на участие в Экоярмарке')
            : $catalogUrl;
        $partnerMail = $email !== ''
            ? 'mailto:' . $email . '?subject=' . rawurlencode('Проведение мероприятия на Экоярмарке')
            : $announcementsUrl;

        return [
            [
                'title' => 'Самая большая продуктовая ярмарка в Алтайском крае',
                'badge' => 'Покупателям',
                'description' => 'Свежие продукты, фермерские точки, витрины продавцов и быстрая навигация по всей Экоярмарке.',
                'theme' => 'buyer',
                'cta_label' => 'Каталог',
                'cta_url' => $catalogUrl,
                'sort_order' => 10,
            ],
            [
                'title' => 'Покупки, анонсы и карта в одном интерфейсе',
                'badge' => 'Покупателям',
                'description' => 'Каталог, события и схема Экоярмарки работают как единая система и не дублируют друг друга.',
                'theme' => 'info',
                'cta_label' => 'Карта',
                'cta_url' => $mapUrl,
                'sort_order' => 20,
            ],
            [
                'title' => 'Площадка для продавцов и партнёров',
                'badge' => 'Продавцам',
                'description' => 'Можно презентовать товары, запускать акции и согласовывать участие в мероприятиях на одной платформе.',
                'theme' => 'seller',
                'cta_label' => 'Написать',
                'cta_url' => $sellerMail,
                'sort_order' => 30,
            ],
            [
                'title' => 'Всё в одном месте',
                'badge' => 'Покупателям',
                'description' => 'Каталог товаров, витрины продавцов, карта площадки и прямой чат в одном публичном интерфейсе.',
                'theme' => 'buyer',
                'cta_label' => 'Смотреть товары',
                'cta_url' => $catalogUrl,
                'sort_order' => 40,
            ],
            [
                'title' => 'Удобная витрина',
                'badge' => 'Продавцам',
                'description' => 'Можно показывать ассортимент, запускать акции и принимать обращения от покупателей.',
                'theme' => 'seller',
                'cta_label' => 'Все анонсы',
                'cta_url' => $announcementsUrl,
                'sort_order' => 50,
            ],
            [
                'title' => 'События и промо',
                'badge' => 'Партнёрам',
                'description' => 'Анонсы, праздники и сезонные кампании встроены в публичную часть маркетплейса.',
                'theme' => 'partner',
                'cta_label' => 'Обсудить',
                'cta_url' => $partnerMail,
                'sort_order' => 60,
            ],
        ];
    }
}
