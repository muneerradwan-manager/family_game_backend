<?php

/*
|--------------------------------------------------------------------------
| ثوابت لعبة الحروف
|--------------------------------------------------------------------------
|
| كل الأرقام الحاكمة للعبة في مكان واحد: مدد المراحل، النقاط، السقوف،
| بنك الحروف، والأعمدة. تعديل الإيقاع لاحقاً = تعديل هذا الملف فقط.
|
*/

return [

    // بنك الحروف: مستبعد منها ث ذ ظ ض ء ى ة لصعوبة الإجابة عليها.
    'letters' => ['ا', 'ب', 'ت', 'ج', 'ح', 'خ', 'د', 'ر', 'ز', 'س', 'ش', 'ص', 'ط', 'ع', 'ف', 'ق', 'ك', 'ل', 'م', 'ن', 'ه', 'و', 'ي'],

    // الأعمدة الثابتة الخمسة.
    'columns' => [
        'name' => 'اسم',
        'animal' => 'حيوان',
        'object' => 'جماد',
        'country' => 'بلاد',
        'food' => 'طعام',
    ],

    // الوضع المرن: 3 أعمدة فقط + وقت أطول، للصغار وكبار السن.
    'flexible_columns' => ['name', 'animal', 'food'],
    'flexible_write_seconds' => 120,

    // العمود السادس الاختياري — قائمة منسدلة لا إدخال حر، حتى تبقى الفئات قابلة للحكم.
    'sixth_columns' => [
        ['key' => 'color', 'label' => 'لون', 'level' => 'easy'],
        ['key' => 'job', 'label' => 'مهنة', 'level' => 'easy'],
        ['key' => 'plant', 'label' => 'نبات', 'level' => 'easy'],
        ['key' => 'kitchen', 'label' => 'شي بالمطبخ', 'level' => 'easy'],
        ['key' => 'clothing', 'label' => 'شي بتلبسه', 'level' => 'easy'],
        ['key' => 'body_part', 'label' => 'جزء من الجسم', 'level' => 'easy'],
        ['key' => 'arab_city', 'label' => 'مدينة عربية', 'level' => 'medium'],
        ['key' => 'brand', 'label' => 'ماركة', 'level' => 'medium'],
        ['key' => 'car', 'label' => 'سيارة', 'level' => 'medium'],
        ['key' => 'sport', 'label' => 'رياضة', 'level' => 'medium'],
        ['key' => 'insect', 'label' => 'حشرة', 'level' => 'medium'],
        ['key' => 'app_or_site', 'label' => 'تطبيق أو موقع', 'level' => 'medium'],
        ['key' => 'celebrity', 'label' => 'فنان أو مشهور', 'level' => 'hard'],
        ['key' => 'movie_or_series', 'label' => 'فيلم أو مسلسل', 'level' => 'hard'],
        ['key' => 'song', 'label' => 'أغنية', 'level' => 'hard'],
        ['key' => 'trait', 'label' => 'صفة أخلاق', 'level' => 'hard'],
    ],

    // خيارات شاشة الشروط.
    'write_seconds_options' => [60, 90, 120],
    'rounds_per_player_options' => [1, 2, 3],

    'defaults' => [
        'sixth_column' => null,
        'rounds_per_player' => 1,
        'write_seconds' => 90,
        'flexible_mode' => false,
    ],

    // مدد المراحل بالثواني. كل مرحلة تنتظر فرداً واحداً لها مؤقّت سيرفر ونتيجة افتراضية.
    'phases' => [
        'awaiting_letter' => 10, // ينتهي بسحب تلقائي من السيرفر بلا عقوبة
        'reveal_letter' => 3,    // أنيميشن + عدّ تنازلي موحّد للجميع
        'grace' => 10,           // بعد الستوب: إكمال الفارغ فقط لا تعديل
        'reveal' => 10,          // قراءة وضحك — بلا أزرار
        'objection' => 20,
        'voting' => 15,          // لكل اعتراض على حدة
        'scoreboard' => 8,
        'tiebreak' => 30,       // جولة الحسم عند تعادل الصدارة
    ],

    // نظام النقاط.
    'points' => [
        'unique' => 10,        // إجابة صحيحة لم يكتبها غيرك
        'duplicate' => 5,      // إجابة صحيحة مكررة
        'sole_answer' => 15,   // الوحيد الذي أجاب في العمود كله
        'empty_or_rejected' => 0,
        'stop_bonus' => 10,    // ضغطت ستوب وكل خاناتك قُبلت
        'stop_penalty' => -10, // ضغطت ستوب ورُفضت ولو خانة
        'failed_objection' => -5,
    ],

    // سقوف منع الفوضى وحماية الإيقاع.
    'limits' => [
        'min_players' => 3,
        'max_players' => 20,
        'objections_per_player' => 3, // بعدها يُقفل زره في هذه الجولة
        'objections_discussed' => 4,  // الباقي يُقبل تلقائياً حمايةً للإيقاع
        'suggest_reduce_above_rounds' => 12,
        'early_end_after_round' => 10,
        'rejoin_grace_rounds' => 2,   // العائد خلال جولتين يستعيد مكانه في الدور
    ],
];
