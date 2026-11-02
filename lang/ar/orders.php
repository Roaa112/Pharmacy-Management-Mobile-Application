<?php

return [
    'status' => [
        'pending' => 'قيد المراجعة',
        'confirmed' => 'تم التأكيد',
        'picking' => 'جاري تجهيز المنتجات',
        'packed' => 'جاهز للشحن',
        'on_delivery' => 'جاري التوصيل',
        'completed' => 'مكتمل',
        'canceled' => 'ملغي',
    ],

    // Exactly what /user/track-order and /user/order-details have always returned in status_meaning.
    // Apps may compare against these strings: do not "fix" them; use the status code instead.
    'status_meaning' => [
        'pending' => 'قيد المرجعة ',
        'confirmed' => 'تم تكيد الطلب وجاري التحضير',
        'on_delivery' => 'جاري الشحن',
        'canceled' => 'تم إلغاء اطلب',
        'completed' => 'تم لاتوصيل بنجاح',
        'unknown' => 'غير معروف',
    ],

    'not_found' => 'الطلب غير موجود',
    'created' => 'تم إنشاء الطلب بنجاح',
    'cart_empty' => 'عربة التسوق فارغة',
    'city_not_covered' => 'المدينة غير معرفة ضمن مصاريف التوصيل',
    'below_minimum' => 'الحد الأدنى للطلب لهذه المنطقة هو :amount',
    'out_of_stock_lines' => 'بعض المنتجات غير متوفرة بالكميات المطلوبة',
    'line_out_of_stock' => 'المنتج غير متوفر حالياً في المخزون',
    'line_insufficient' => 'الكمية المطلوبة غير متوفرة، المتاح فقط: :available',
    'unknown_product' => 'منتج غير معروف',
    'payment_method_unavailable' => 'طريقة الدفع غير متاحة',
    'receipt_required' => 'يجب إرفاق صورة إيصال الدفع لهذه الطريقة',
    'cancel_not_allowed' => 'لا يمكن إلغاء هذا الطلب',
    'canceled' => 'تم إلغاء الطلب',
    'transition_refused' => 'لا يمكن تغيير حالة الطلب من :from إلى :to',
    'awaiting_online_payment' => 'لم يتم تأكيد الدفع الإلكتروني بعد',
    'status_updated' => 'تم تحديث حالة الطلب بنجاح',
    'size_unavailable' => 'غير متوفر',
    'gift_description' => 'هدية: :product',
    'coupon_rejected' => 'لا يمكن استخدام كود الخصم: :reason',
];
