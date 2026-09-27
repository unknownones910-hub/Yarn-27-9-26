<?php
/**
 * Migration 005 — policy pages and FAQs.
 *
 * A PHP migration rather than .sql because this is several thousand words of
 * prose full of apostrophes; escaping it into SQL literals would be asking for
 * a silent truncation bug.
 *
 * The text uses {{tokens}} which policy.php resolves from settings at render
 * time. That way the shop's address, phone number and grievance officer appear
 * in eight documents but are edited in exactly one place, and they can never
 * drift apart.
 *
 * NOT LEGAL ADVICE. These are thorough, industry-standard drafts aligned to
 * the Consumer Protection (E-Commerce) Rules 2020, the Legal Metrology
 * (Packaged Commodities) Rules 2011, the IT Act 2000 with the SPDI Rules 2011,
 * and the DPDP Act 2023. Have a lawyer read them before launch.
 *
 * @var mysqli $conn  provided by install.php
 */
declare(strict_types=1);

$pages = [];

// ---------------------------------------------------------------------
$pages['shipping-policy'] = ['Shipping & Delivery Policy', <<<'HTML'
<p>We ship across India. This page explains when your parcel leaves us, what it costs,
and what happens if something goes wrong on the way.</p>

<h2>Dispatch time</h2>
<p>Orders are packed and handed to the courier within <strong>{{dispatch_days}} business days</strong>
of payment being confirmed. Business days are Monday to Saturday, excluding public holidays.
If an item is delayed for any reason, we email you rather than letting you wonder.</p>

<h2>Delivery time</h2>
<p>Once dispatched, typical delivery times are:</p>
<ul>
  <li><strong>Metro cities</strong> (Mumbai, Delhi, Bengaluru, Chennai, Hyderabad, Kolkata, Pune,
      Ahmedabad) — 3 to 5 business days</li>
  <li><strong>Rest of India</strong> — 5 to 8 business days</li>
  <li><strong>North-East, Jammu &amp; Kashmir, Ladakh, Andaman &amp; Nicobar Islands</strong> —
      7 to 12 business days</li>
</ul>
<p>These are the courier's working estimates, not guarantees. Festival periods, weather and
local disruptions can add time.</p>

<h2>Delivery charges</h2>
<ul>
  <li>Flat <strong>{{flat_rate}}</strong> on every order</li>
  <li><strong>Free delivery</strong> on orders above {{free_threshold}}</li>
</ul>
<p>The free-delivery threshold is applied to your order value <em>before</em> any discount code,
so using a coupon never adds a delivery charge. The exact amount is always shown on the cart
page before you reach payment. We never add a charge after you have paid.</p>

<h2>Tracking</h2>
<p>When your order is dispatched we email you the courier name and tracking number. You can also
check progress at any time on our <a href="/track-order.php">order tracking page</a> using your
order number and the email address you used at checkout — no account needed.</p>

<h2>Delivery attempts</h2>
<p>Couriers usually attempt delivery two or three times. Please keep the phone number on your
order reachable — most failed deliveries happen because the courier could not make contact.
If all attempts fail, the parcel returns to us and we will contact you to arrange redelivery.
Re-shipping charges may apply where the address was incomplete or nobody was available.</p>

<h2>Incorrect or incomplete addresses</h2>
<p>Please check your address carefully before paying. If a parcel is returned to us because the
address was wrong or incomplete, we will refund the order value but not the original delivery
charge, and re-shipping will be charged again.</p>

<h2>Parcel not received</h2>
<p>If tracking shows delivered but you have not received the parcel, tell us within
<strong>7 days</strong> of the delivery date so we can raise it with the courier. After that
window the courier will not accept a claim and there is little we can do.</p>

<h2>Damaged or tampered parcels</h2>
<p>If a parcel arrives visibly damaged or the tape has been tampered with, please refuse delivery
where you can, or photograph it before opening. Report it to us within <strong>48 hours</strong>.
An unboxing video makes these claims straightforward for everyone.</p>

<h2>Partial shipments</h2>
<p>Large orders are occasionally split across more than one parcel. There is never an extra
charge for this, and each parcel gets its own tracking number.</p>

<h2>Where we ship</h2>
<p>We currently ship only within India. We do not deliver to a small number of pincodes our
courier partners do not serve — the pincode checker on each product page will tell you before
you order.</p>

<h2>Questions</h2>
<p>Email <a href="mailto:{{email}}">{{email}}</a> or call {{phone}}. We reply within one
working day.</p>
HTML];

// ---------------------------------------------------------------------
$pages['returns-and-refunds'] = ['Returns, Refunds & Exchange Policy', <<<'HTML'
<p>We want you to be happy with your yarn. If something is wrong, tell us and we will put it
right. This page sets out exactly what we can and cannot accept back, so there are no
surprises either way.</p>

<h2>The short version</h2>
<p>You have <strong>{{return_days}} days from delivery</strong> to raise a return on unused,
unopened yarn in its original band. Damaged, defective or wrong items are always our problem
to fix.</p>

<h2>What we can accept</h2>
<ul>
  <li>Yarn that is unused, unwound and unwashed, with the original ball band intact</li>
  <li>Items that arrived damaged or defective</li>
  <li>The wrong item or wrong shade sent by us</li>
  <li>Accessories and tools in their original unopened packaging</li>
</ul>

<h2>What we cannot accept</h2>
<ul>
  <li>Yarn that has been wound into a cake or ball, cut, crocheted, knitted or washed</li>
  <li>Yarn with the ball band removed or destroyed — we cannot identify the dye lot without it</li>
  <li>Items marked final sale or clearance</li>
  <li>Physical shade cards</li>
  <li>Downloaded digital patterns</li>
  <li>Anything damaged by misuse after delivery</li>
</ul>
<p>This is not us being difficult: once a ball is wound we cannot sell it to anyone else, and
we would be throwing it away.</p>

<h2>Dye lot variation is not a defect</h2>
<p>Yarn is dyed in batches, and shades vary slightly between batches. Screens also vary — the
same shade looks different on two phones. We photograph every shade in natural daylight and
state the current dye lot on each product page, but small variation is a normal characteristic
of dyed yarn and is not grounds for a return. If you need an exact match, order our
<a href="/shade-card.php">physical shade card</a> first, and buy all the yarn for one project
in a single order.</p>

<h2>Damaged, defective or wrong item</h2>
<p>Report it within <strong>48 hours</strong> of delivery with photographs. An unboxing video
helps enormously and we will ask for one where the parcel looks interfered with. We will
arrange a free pickup or send a replacement, whichever you prefer. You will never pay return
shipping when the fault is ours.</p>

<h2>Changed your mind</h2>
<p>Unopened yarn can be returned within {{return_days}} days. The return shipping cost is
deducted from your refund, and the original delivery charge is not refunded.</p>

<h2>How to raise a return</h2>
<ol>
  <li>Sign in and open the order under <a href="/account/orders.php">My orders</a>, then choose
      <strong>Request a return</strong>. You can also email {{email}} with your order number.</li>
  <li>Tell us which items and why. Add photos if the item is damaged.</li>
  <li>We reply within one working day with pickup or self-ship instructions.</li>
  <li>Pack the yarn as you received it, with the ball band on.</li>
</ol>

<h2>Refund timeline</h2>
<ul>
  <li>We inspect and approve returns within <strong>2 business days</strong> of receiving them.</li>
  <li>The refund is then issued to your <strong>original payment method</strong>.</li>
  <li>Your bank typically takes a further <strong>5 to 7 business days</strong> to show it.</li>
</ul>
<p>Refunds only ever go back to the method you paid with. We cannot refund to a different card,
a different UPI ID or as cash. This is a payment-industry rule, not our preference.</p>

<h2>Exchanges</h2>
<p>We are happy to exchange an unopened ball for a different shade, subject to stock. Any price
difference is collected or refunded. Exchange shipping is charged at the normal flat rate
unless the original error was ours.</p>

<h2>Failed and cancelled payments</h2>
<p>If a payment fails but money left your account, it is automatically reversed by your bank,
usually within 5 to 7 working days. Nothing needs to be raised with us — but do tell us if it
has not appeared after a week, and we will chase it with the gateway.</p>

<h2>Still not right?</h2>
<p>Contact our grievance officer, {{grievance_name}}, at
<a href="mailto:{{grievance_email}}">{{grievance_email}}</a>. See our
<a href="/grievance-redressal">grievance redressal page</a> for the full escalation path.</p>
HTML];

// ---------------------------------------------------------------------
$pages['cancellation-policy'] = ['Cancellation Policy', <<<'HTML'
<h2>Cancelling before dispatch</h2>
<p>You can cancel any order free of charge at any time <strong>before it is dispatched</strong>.
Sign in, open <a href="/account/orders.php">My orders</a> and choose <strong>Cancel order</strong>,
or email us with your order number.</p>
<p>If you have already paid, the full amount — including any delivery charge — is refunded to
your original payment method. Your bank usually takes 5 to 7 business days to show it.</p>

<h2>After dispatch</h2>
<p>Once a parcel has left us it cannot be cancelled. You have two options:</p>
<ul>
  <li>Refuse the delivery when the courier arrives, and it comes back to us</li>
  <li>Accept it and raise a return under our
      <a href="/returns-and-refunds">returns policy</a></li>
</ul>
<p>Where the parcel returns to us unopened, we refund the order value less the delivery charge
we have already paid the courier.</p>

<h2>Unpaid orders</h2>
<p>An order created but not paid for is automatically cancelled after 60 minutes and the stock
is released. Nothing is charged. You can simply place the order again.</p>

<h2>Cancellation by us</h2>
<p>Occasionally we have to cancel an order. We will always email you first and explain why.
The reasons are:</p>
<ul>
  <li>The item turned out to be out of stock despite the website showing otherwise</li>
  <li>A pricing or description error on our side</li>
  <li>The delivery pincode is not serviceable by our courier partners</li>
  <li>We could not reach you to confirm an incomplete address</li>
  <li>We reasonably suspect fraudulent or abusive ordering</li>
</ul>
<p>In every case you receive a <strong>full refund</strong>, including delivery. Where we
cancel because of our own error, we will usually also offer a discount on your next order —
the mistake was ours, not yours.</p>

<h2>Partial cancellation</h2>
<p>Want to remove one item rather than the whole order? Email {{email}} with your order number
before it is dispatched and we will refund that line and send the rest.</p>
HTML];

// ---------------------------------------------------------------------
$pages['privacy-policy'] = ['Privacy Policy', <<<'HTML'
<p>{{legal_name}} ("we", "us") operates {{store_name}}. This policy explains what personal data
we collect, why, and what you can do about it. It is written to comply with the Digital Personal
Data Protection Act, 2023, the Information Technology Act, 2000 and the SPDI Rules, 2011.</p>

<h2>Who to contact</h2>
<p>{{legal_name}}<br>{{address}}<br>
Email: <a href="mailto:{{email}}">{{email}}</a> · Phone: {{phone}}</p>
<p>Grievance Officer: <strong>{{grievance_name}}</strong>,
<a href="mailto:{{grievance_email}}">{{grievance_email}}</a></p>

<h2>What we collect</h2>
<ul>
  <li><strong>Your email address.</strong> This is how you sign in — we email a one-time code
      instead of using a password.</li>
  <li><strong>Name, phone number and delivery address</strong>, so a courier can find you.</li>
  <li><strong>Order history</strong>, including what you bought and what you paid.</li>
  <li><strong>Optional profile details</strong> you choose to add: birthday, craft preferences,
      skill level.</li>
  <li><strong>Technical data</strong>: IP address, browser type, and which pages you viewed.</li>
  <li><strong>Anything you write to us</strong> in a support ticket, review or contact form.</li>
</ul>

<h2>What we never collect</h2>
<p>We do not see or store your card number, CVV, UPI PIN or netbanking credentials. Payments are
handled entirely by Razorpay on their own systems. We receive only a payment reference and
whether it succeeded. We also do not store passwords, because the site does not use them.</p>

<h2>Why we use it</h2>
<ul>
  <li>To take, pack and deliver your order, and to handle returns and refunds</li>
  <li>To verify it is really you signing in, using a one-time code</li>
  <li>To answer your questions and provide support</li>
  <li>To send transactional email: order confirmations, dispatch notices, refunds</li>
  <li>To prevent fraud and abuse</li>
  <li>To send marketing email — <strong>only if you have opted in</strong></li>
  <li>To meet our legal obligations, including keeping tax and invoice records</li>
</ul>

<h2>Consent, and taking it back</h2>
<p>We rely on your consent for marketing, and on the necessity of performing our contract with
you for everything involved in fulfilling an order. You can withdraw marketing consent at any
time from <a href="/account/notifications.php">your notification settings</a> or the one-click
unsubscribe link in any marketing email. Withdrawing it does not affect orders already placed,
and you will still receive email about those.</p>

<h2>Who we share it with</h2>
<p>We do not sell your data. We never have and we will not. We share the minimum necessary with:</p>
<ul>
  <li><strong>Courier partners</strong> — your name, address and phone number, so they can
      deliver the parcel</li>
  <li><strong>Razorpay</strong> — to take payment and issue refunds
      (<a href="https://razorpay.com/privacy/" rel="noopener" target="_blank">their privacy policy</a>)</li>
  <li><strong>Brevo</strong> — to send our email
      (<a href="https://www.brevo.com/legal/privacypolicy/" rel="noopener" target="_blank">their privacy policy</a>)</li>
  <li><strong>Our web host</strong>, which stores the database</li>
  <li><strong>Law enforcement or a court</strong>, where we are legally required to</li>
</ul>

<h2>How we protect it</h2>
<ul>
  <li>The whole site runs over HTTPS</li>
  <li>Sign-in codes are stored only as salted hashes, never in readable form</li>
  <li>There are no passwords in the system to leak</li>
  <li>Admin access needs both an account and an explicit allowlist entry</li>
  <li>Every administrative action is logged</li>
</ul>
<p>No system is perfectly secure, and we will not pretend otherwise. If a breach affects your
data we will tell you and the relevant authority as required by law.</p>

<h2>How long we keep it</h2>
<ul>
  <li><strong>Account details</strong> — until you close your account</li>
  <li><strong>Order and invoice records</strong> — retained as required by Indian tax and
      consumer-protection law, even after you close your account, but stripped of your name,
      address and phone number</li>
  <li><strong>Sign-in codes</strong> — deleted within days; they expire in 10 minutes</li>
  <li><strong>Support conversations</strong> — up to 3 years</li>
</ul>

<h2>Your rights</h2>
<ul>
  <li><strong>Access</strong> — see what we hold about you, most of it visible in your account</li>
  <li><strong>Correction</strong> — fix anything wrong, from
      <a href="/account/profile.php">your profile</a></li>
  <li><strong>Erasure</strong> — close your account and have your personal data deleted, from
      <a href="/account/delete.php">the account deletion page</a>. You do not need to ask us.</li>
  <li><strong>Withdraw consent</strong> — turn marketing off at any time</li>
  <li><strong>Grievance redressal</strong> — complain to our Grievance Officer, and escalate
      to the Data Protection Board of India if we do not resolve it</li>
  <li><strong>Nomination</strong> — nominate someone to exercise your rights if you die or
      become incapacitated. Email us to arrange this.</li>
</ul>

<h2>Children</h2>
<p>This shop is not intended for anyone under 18. We do not knowingly collect data from
children. If you believe a child has given us data, contact us and we will delete it.</p>

<h2>Cookies</h2>
<p>See our <a href="/cookie-policy">cookie policy</a>. In short: we use the few cookies needed
to keep you signed in and your cart working, and no advertising trackers.</p>

<h2>Changes</h2>
<p>If we change this policy we will update the date at the top of the page, and email you if
the change is significant.</p>
HTML];

// ---------------------------------------------------------------------
$pages['terms-and-conditions'] = ['Terms & Conditions', <<<'HTML'
<p>These terms govern your use of {{store_name}}, operated by {{legal_name}}. By placing an
order you agree to them.</p>

<h2>Who we are</h2>
<p>{{legal_name}}<br>{{address}}<br>
{{phone}} · <a href="mailto:{{email}}">{{email}}</a></p>

<h2>Eligibility</h2>
<p>You must be 18 or older to buy from us, or have a parent or guardian place the order.</p>

<h2>Your account</h2>
<p>We accept Gmail addresses only, and sign you in with a one-time code rather than a password.
Keep access to your email secure — anyone who can read your inbox can sign in as you. Please
use one account per person. Tell us immediately if you think someone else has used your account.</p>

<h2>Products and descriptions</h2>
<p>We describe every yarn as accurately as we can: fibre composition, weight, ball weight and
meterage are as supplied by the manufacturer, within normal manufacturing tolerance. We
photograph shades in natural daylight, but screens vary and dye lots vary — see the note in our
<a href="/returns-and-refunds">returns policy</a>. Recommended hook and needle sizes and gauge
are guidance, not a guarantee. Always work a tension swatch.</p>

<h2>Prices and payment</h2>
<ul>
  <li>All prices are in Indian Rupees and inclusive of applicable taxes.</li>
  <li>The price you see at checkout is the price you pay. Nothing is added afterwards.</li>
  <li>Payment is taken by Razorpay and subject to their terms.</li>
  <li>Prices can change at any time, but never after you have placed an order.</li>
</ul>
<p>Very occasionally a price is published in error. Where the error is obvious, we may cancel
the affected orders and refund you in full rather than honour it. We will always email you
first.</p>

<h2>When a contract is formed</h2>
<p>Adding items to your cart or reaching the payment page does not create a contract. A contract
exists only when we send you an order confirmation. Until then we may decline the order for the
reasons set out in our <a href="/cancellation-policy">cancellation policy</a>.</p>

<h2>Delivery, returns and cancellation</h2>
<p>Our <a href="/shipping-policy">shipping</a>, <a href="/returns-and-refunds">returns</a> and
<a href="/cancellation-policy">cancellation</a> policies form part of these terms.</p>

<h2>What you may not do</h2>
<ul>
  <li>Scrape, copy or republish our photographs, descriptions or patterns</li>
  <li>Buy to resell without a wholesale agreement with us</li>
  <li>Attempt to break, overload or probe the site's security</li>
  <li>Place fraudulent orders or abuse our returns process</li>
</ul>

<h2>Intellectual property</h2>
<p>The photographs, product descriptions, page copy and original patterns on this site belong
to us. Our <strong>free patterns are for personal use</strong>. You may sell a small number of
finished items you make from them, and a credit is appreciated, but you may not redistribute,
resell or republish the pattern file itself.</p>

<h2>Reviews and content you post</h2>
<p>By posting a review or photograph you confirm it is your own and give us permission to
display it on the site and in our marketing. We publish honest criticism — we only remove
content that is abusive, off-topic, or not from a real buyer.</p>

<h2>Limitation of liability</h2>
<p>Our liability for any order is limited to the value of that order. We are not liable for
indirect or consequential loss, such as the value of your time or a project you were unable to
finish. Nothing here limits liability that cannot be limited under Indian law, including
liability for death or personal injury caused by negligence.</p>

<h2>Indemnity</h2>
<p>You agree to indemnify us against claims arising from your misuse of the site or breach of
these terms.</p>

<h2>Force majeure</h2>
<p>We are not liable for delays caused by events outside our reasonable control, including
natural disasters, strikes, courier failures, civil unrest and government restrictions.</p>

<h2>Governing law</h2>
<p>These terms are governed by the laws of India. The courts at
<strong>{{city}}, {{state}}</strong> have exclusive jurisdiction over any dispute.</p>

<h2>Changes</h2>
<p>We may update these terms. The version in force is the one published when you place your
order.</p>
HTML];

// ---------------------------------------------------------------------
$pages['cookie-policy'] = ['Cookie Policy', <<<'HTML'
<p>Cookies are small files a website stores in your browser. We use as few as we can get away
with, and none of them follow you around the internet.</p>

<h2>What we set</h2>

<h3>Strictly necessary</h3>
<p>These make the site work. They cannot be turned off, and the shop will not function without
them.</p>
<ul>
  <li><strong>YARNNEST_SESS</strong> — keeps you signed in and remembers your cart during a
      visit. Expires when you close the browser.</li>
  <li><strong>Security token</strong> — protects forms against cross-site request forgery.</li>
  <li><strong>Cart token</strong> — lets you build a cart before signing in.</li>
  <li><strong>YARNNEST_ADM</strong> — only set for shop staff signing into the admin panel.</li>
</ul>

<h3>Functional</h3>
<p>These remember small preferences. The site works without them; it is just slightly less
convenient.</p>
<ul>
  <li><strong>yn_rmb</strong> — the "keep me signed in" token. 30 days. Rotates on every use.</li>
  <li><strong>yn_pincode</strong> — the pincode you last checked, so you do not retype it.</li>
  <li><strong>yn_announce_hidden</strong> — remembers that you dismissed the banner at the top.</li>
  <li><strong>yn_wishlist_v</strong> — holds a wishlist saved before you signed in.</li>
</ul>

<h3>Analytics and advertising</h3>
<p>We do not currently use any. There are no Google Analytics, Facebook Pixel or advertising
cookies on this site. That is why you have not been shown a consent banner — there is nothing
to consent to. If we ever add analytics, we will ask you first.</p>

<h2>Third parties</h2>
<p>When you pay, Razorpay's checkout sets its own cookies to process the transaction securely.
That happens on their systems, under
<a href="https://razorpay.com/privacy/" rel="noopener" target="_blank">their privacy policy</a>.</p>

<h2>Controlling cookies</h2>
<p>Every browser lets you view, block and delete cookies in its settings. Be aware that blocking
the strictly necessary ones means you will not be able to sign in or keep anything in your
cart.</p>

<h2>More</h2>
<p>See our <a href="/privacy-policy">privacy policy</a> for how we handle personal data
generally.</p>
HTML];

// ---------------------------------------------------------------------
$pages['disclaimer'] = ['Disclaimer', <<<'HTML'
<h2>Colours and photographs</h2>
<p>We photograph every shade in natural daylight without colour filters. Even so, screens differ
enormously — the same photograph looks warmer on one phone than another. Treat our images as a
close guide, not an exact match. If colour accuracy matters for your project, order our
<a href="/shade-card.php">physical shade card</a>.</p>

<h2>Dye lots</h2>
<p>Yarn is dyed in batches and shades vary slightly between them. We show the current dye lot on
each product page. For a single project, buy all the yarn you need in one order so it comes from
the same lot.</p>

<h2>Fibre content and meterage</h2>
<p>Fibre composition, ball weight and length are as declared by the manufacturer and are subject
to the usual manufacturing tolerance. Natural fibres in particular vary a little from ball to
ball.</p>

<h2>Gauge, hooks and needles</h2>
<p>Recommended hook and needle sizes and stated gauge are a starting point. Everyone's tension
differs. Always work a swatch before starting a garment — this is the single most common cause
of a finished item coming out the wrong size, and it is not something we can be responsible for.</p>

<h2>Patterns</h2>
<p>Our free patterns are provided as they are, without warranty. We have tested them, but we
cannot guarantee a result with a different yarn, a different tension or a different hook. We
are not responsible for patterns published by third parties, even where we link to them.</p>

<h2>Allergies and sensitivities</h2>
<p>Some people react to particular fibres, especially wool and mohair. Fibre content is listed
on every product page. If you have a known sensitivity, please check before ordering.</p>

<h2>Safety</h2>
<p>Finished items containing small parts, wire or long strands are not suitable for babies and
young children unless made specifically for that purpose. Use safety eyes correctly and secure
all stuffing. Please use your own judgement.</p>

<h2>External links</h2>
<p>Where we link to another website, that is not an endorsement and we have no control over
their content.</p>
HTML];

// ---------------------------------------------------------------------
$pages['grievance-redressal'] = ['Grievance Redressal', <<<'HTML'
<p>If something has gone wrong and normal support has not fixed it, this page tells you exactly
who to contact and what happens next. Publishing this is required of us under the Consumer
Protection (E-Commerce) Rules, 2020 and the Information Technology (Intermediary Guidelines)
Rules, 2021 — but we would rather you never need it.</p>

<h2>Step 1 — Regular support</h2>
<p>Most problems are solved fastest here.</p>
<ul>
  <li>Email <a href="mailto:{{email}}">{{email}}</a></li>
  <li>Call {{phone}}</li>
  <li>Or open a ticket from <a href="/account/support.php">your account</a></li>
</ul>
<p>We reply within <strong>one working day</strong>.</p>

<h2>Step 2 — Grievance Officer</h2>
<p>If support has not resolved it, escalate to our Grievance Officer.</p>
<p><strong>{{grievance_name}}</strong><br>
Grievance Officer, {{legal_name}}<br>
{{address}}<br>
Email: <a href="mailto:{{grievance_email}}">{{grievance_email}}</a><br>
Phone: {{phone}}</p>
<p>Please include your order number and a short description of what happened.</p>

<h3>Our commitment</h3>
<ul>
  <li>We <strong>acknowledge</strong> every grievance within <strong>48 hours</strong>.</li>
  <li>We <strong>resolve</strong> it within <strong>one month</strong> of receipt.</li>
  <li>You get a written reply explaining the outcome, whatever it is.</li>
</ul>

<h2>Step 3 — Consumer authorities</h2>
<p>If you are still not satisfied, you can approach:</p>
<ul>
  <li><strong>National Consumer Helpline</strong> — dial <strong>1915</strong>, or visit
      <a href="https://consumerhelpline.gov.in" rel="noopener" target="_blank">consumerhelpline.gov.in</a></li>
  <li><strong>e-Daakhil</strong>, the online consumer forum —
      <a href="https://edaakhil.nic.in" rel="noopener" target="_blank">edaakhil.nic.in</a></li>
  <li>Your <strong>District Consumer Disputes Redressal Commission</strong></li>
</ul>
<p>For a complaint specifically about how we handled your personal data, you may also approach
the <strong>Data Protection Board of India</strong> under the DPDP Act, 2023.</p>

<h2>What helps us fix things faster</h2>
<ul>
  <li>Your order number</li>
  <li>Photographs, if the problem is physical</li>
  <li>What you would like us to do about it</li>
</ul>
HTML];

// ---------------------------------------------------------------------
//  Insert pages — never overwrite text the owner has since edited.
// ---------------------------------------------------------------------
$n = 0;
$stmt = $conn->prepare(
    'INSERT INTO pages (slug, title, body, meta_title, meta_description, is_active)
     VALUES (?, ?, ?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE slug = slug'
);

foreach ($pages as $slug => [$title, $body]) {
    $metaTitle = $title;
    $metaDesc  = mb_substr(trim(strip_tags(preg_replace('/<h[1-6][^>]*>.*?<\/h[1-6]>/s', '', $body) ?? '')), 0, 300);
    $metaDesc  = preg_replace('/\s+/', ' ', $metaDesc) ?? '';
    $metaDesc  = mb_substr($metaDesc, 0, 155);

    $stmt->bind_param('sssss', $slug, $title, $body, $metaTitle, $metaDesc);
    $stmt->execute();
    $n += $conn->affected_rows > 0 ? 1 : 0;
}

// ---------------------------------------------------------------------
//  FAQs
// ---------------------------------------------------------------------
$faqs = [
    ['Ordering', 'Do I need an account to order?',
     'You can browse and fill a cart without one. At checkout we ask for your Gmail address and email you a 6-digit code — that verifies the order is really yours and gives you a place to track it. There is no password to remember.'],
    ['Ordering', 'Why do you only accept Gmail addresses?',
     'It keeps sign-in simple and reliable, and it stops one person accidentally ending up with several accounts. If you do not have a Gmail address, message us on WhatsApp and we will place the order for you.'],
    ['Ordering', 'Can I change or cancel my order?',
     'Yes, free of charge, any time before it is dispatched — open the order in your account and choose Cancel. After dispatch you can refuse delivery or raise a return.'],
    ['Ordering', 'Do you take bulk or wholesale orders?',
     'We do. Tell us what you need on the wholesale enquiry page and we will come back with pricing. It is worth asking if you teach classes or run a boutique.'],

    ['Shipping', 'How long will my order take?',
     'We dispatch within 1-2 business days. Delivery is usually 3-5 days to metros, 5-8 days elsewhere, and 7-12 days to the North-East, J&amp;K, Ladakh and the islands.'],
    ['Shipping', 'What does delivery cost?',
     'A flat rate per order, and it is free above our threshold — both are shown on the cart page before you pay. The free-delivery threshold is worked out before any discount code, so using a coupon never adds a delivery charge.'],
    ['Shipping', 'Do you deliver to my pincode?',
     'Almost certainly. Use the pincode checker on any product page to see the expected delivery date for your area, and it will tell you if we cannot reach you.'],
    ['Shipping', 'How do I track my parcel?',
     'We email a tracking link when it is dispatched. You can also use our track order page with just your order number and email — no sign-in needed.'],

    ['Returns', 'Can I return yarn I have started using?',
     'Unfortunately not. Once a ball has been wound, cut or worked we cannot resell it. Unopened yarn with its ball band intact can be returned within our return window.'],
    ['Returns', 'The shade looks different from the photo. Can I return it?',
     'Dye lots and screens both vary, so small differences are normal and not a defect. If the shade is genuinely wrong — not the one you ordered — that is our mistake and we will fix it at our cost. For an exact match before you buy, order our physical shade card.'],
    ['Returns', 'My parcel arrived damaged. What do I do?',
     'Tell us within 48 hours with photos. If the parcel looked tampered with, an unboxing video helps a lot. We arrange a free pickup or send a replacement — you never pay return shipping when the fault is ours.'],
    ['Returns', 'How long does a refund take?',
     'We approve returns within 2 business days of receiving them. The money then takes 5-7 business days to reach your original payment method, depending on your bank.'],

    ['Payments', 'How can I pay?',
     'UPI, credit and debit cards, netbanking and wallets, all through Razorpay. Your card details never touch our servers.'],
    ['Payments', 'Do you offer cash on delivery?',
     'Not at the moment. Prepaid orders let us keep prices lower and dispatch faster.'],
    ['Payments', 'Money left my account but the order failed. What now?',
     'Failed payments are reversed automatically by your bank, usually within 5-7 working days. If it has not appeared after a week, email us with your order number and we will chase the gateway.'],
    ['Payments', 'Will I get an invoice?',
     'Yes. It is attached to your order confirmation email as a PDF, and you can download it any time from your account.'],

    ['Yarn & products', 'How much yarn do I need for my project?',
     'Use our yarn calculator — tell it the project and size and it works out the meterage and roughly how many balls that is. Always add about 10% as a margin.'],
    ['Yarn & products', 'What is a dye lot and why does it matter?',
     'Yarn is dyed in batches, and each batch varies very slightly. Two balls from different lots can look subtly different in a finished piece. We show the current lot on every product page — buy all the yarn for a project in one order.'],
    ['Yarn & products', 'Which yarn should I use for amigurumi?',
     'A tight-twist cotton, worked on a hook one or two sizes smaller than the band suggests, so the stuffing does not show through. Our Amigurumi Cotton is spun specifically for this.'],
    ['Yarn & products', 'Is your yarn machine washable?',
     'Most of it. Every product page lists the care instructions for that specific yarn. Superwash wool and acrylics are machine washable; untreated wool and mohair usually are not.'],

    ['Account', 'I did not receive my sign-in code.',
     'Check your Promotions tab and spam folder first — that is where it usually is. Codes expire after 10 minutes, so request a fresh one if it has been a while. Still stuck? Message us on WhatsApp.'],
    ['Account', 'How do I delete my account?',
     'From the account deletion page — you do not need to ask us. We erase your personal data and keep only the invoice records that tax law requires, with your name and address removed from them.'],
    ['Account', 'How do I stop marketing emails?',
     'Turn them off in your notification settings, or click unsubscribe in any marketing email. You will still get emails about orders you have placed.'],
];

$fstmt = $conn->prepare(
    'INSERT INTO faqs (category, question, answer, sort_order, is_active) VALUES (?,?,?,?,1)'
);
$existing = (int) $conn->query('SELECT COUNT(*) FROM faqs')->fetch_row()[0];

if ($existing === 0) {
    foreach ($faqs as $i => [$cat, $q, $a]) {
        $fstmt->bind_param('sssi', $cat, $q, $a, $i);
        $fstmt->execute();
        $n++;
    }
}

return $n;
