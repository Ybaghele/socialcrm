<script setup>
import { computed, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

const props = defineProps({
    canLogin: {
        type: Boolean,
        default: true,
    },
    canRegister: {
        type: Boolean,
        default: true,
    },
    plans: {
        type: Array,
        default: () => [],
    },
});

const page = usePage();
const isAuthed = computed(() => !!page.props.auth?.user);
const mobileOpen = ref(false);

// Pricing falls back to the seed values when the `plans` table is empty, so the
// page never renders an empty section on a fresh install.
const fallbackPlans = [
    { name: 'Starter Plan', slug: 'starter', priceRupees: 1999, contacts: 500, instagram: 1, seats: 2 },
    { name: 'Growth Plan', slug: 'growth', priceRupees: 4999, contacts: 2500, instagram: 5, seats: 5 },
    { name: 'Scale Plan', slug: 'scale', priceRupees: 14999, contacts: 10000, instagram: 15, seats: 20 },
];

const pricing = computed(() => (props.plans?.length ? props.plans : fallbackPlans));

// Highlight the middle tier (Growth) when present, otherwise the middle card.
const highlightSlug = computed(() => {
    if (pricing.value.some((plan) => plan.slug === 'growth')) {
        return 'growth';
    }

    return pricing.value[Math.floor((pricing.value.length - 1) / 2)]?.slug;
});

const money = (value) => '₹' + new Intl.NumberFormat('en-IN').format(Number(value) || 0);

const navLinks = [
    { label: 'Features', href: '#features' },
    { label: 'How it works', href: '#how-it-works' },
    { label: 'Pricing', href: '#pricing' },
    { label: 'FAQ', href: '#faq' },
];

const channels = [
    { label: 'WhatsApp', icon: 'fa-brands fa-whatsapp', tone: 'text-emerald-600' },
    { label: 'Instagram', icon: 'fa-brands fa-instagram', tone: 'text-rose-500' },
    { label: 'Messenger', icon: 'fa-brands fa-facebook-messenger', tone: 'text-blue-600' },
];

const features = [
    {
        icon: 'fa-solid fa-inbox',
        title: 'One unified inbox',
        body: 'WhatsApp, Instagram and Messenger conversations land in a single, assignment-ready queue — no tab-hopping, no missed replies.',
    },
    {
        icon: 'fa-solid fa-comment-dots',
        title: 'Meta-approved broadcasts',
        body: 'Send WhatsApp template broadcasts to opted-in contacts, with per-variable personalisation and live delivered counts.',
    },
    {
        icon: 'fa-solid fa-users-viewfinder',
        title: 'Lightweight CRM',
        body: 'Every conversation builds a contact record: tags, opt-in status, city and history you can filter and act on.',
    },
    {
        icon: 'fa-solid fa-bolt',
        title: 'Template library',
        body: 'Sync your approved message templates straight from Meta and reuse them for replies and campaigns alike.',
    },
    {
        icon: 'fa-solid fa-plug-circle-check',
        title: 'Embedded channel connect',
        body: 'Onboard a WhatsApp Business number through Meta Embedded Signup, or paste credentials for an existing number.',
    },
    {
        icon: 'fa-solid fa-wallet',
        title: 'Wallet & billing',
        body: 'Top up a prepaid wallet, send within your plan limits, and let overages bill transparently per conversation.',
    },
];

const steps = [
    {
        title: 'Connect your channels',
        body: 'Link a WhatsApp Business number, an Instagram account and a Facebook Page in minutes with guided setup.',
    },
    {
        title: 'Talk to every customer',
        body: 'Work the unified inbox as a team — assign threads, reply inside the 24-hour window, and log notes as you go.',
    },
    {
        title: 'Broadcast & grow',
        body: 'Launch template campaigns to opted-in subscribers, then track delivery and reply rates in one place.',
    },
];

const faqs = [
    {
        q: 'Which channels does OmniFlow support?',
        a: 'WhatsApp Business, Instagram Direct and Facebook Messenger. All three share the same inbox, contact records and campaign tools.',
    },
    {
        q: 'How do WhatsApp broadcasts work?',
        a: 'You author message templates in Meta Business Manager. Once Meta approves them, sync them into OmniFlow, pick an audience of opted-in contacts, fill in any variables and launch.',
    },
    {
        q: 'Do I need Meta Business Verification?',
        a: 'To message real customers on your own WhatsApp number you will complete Meta Business Verification and app review. You can explore the whole product with a trial before that is finished.',
    },
    {
        q: 'How is usage billed?',
        a: 'A flat monthly subscription covers your plan limits (contacts, Instagram accounts and agent seats). Outbound template messages and overages are drawn from a prepaid wallet you top up.',
    },
    {
        q: 'Is my data isolated from other businesses?',
        a: 'Yes. Every workspace is fully multi-tenant — contacts, conversations, channels and billing are scoped to your account and never shared.',
    },
];

const primaryCta = computed(() => (props.canRegister ? route('register') : route('login')));
</script>

<template>
    <Head title="OmniFlow — WhatsApp, Instagram & Messenger inbox, CRM and broadcasts">
        <meta
            name="description"
            content="OmniFlow brings WhatsApp, Instagram and Messenger into one inbox, with a built-in CRM, Meta-approved template broadcasts and transparent wallet billing."
        />
        <meta property="og:title" content="OmniFlow — one inbox for WhatsApp, Instagram & Messenger" />
        <meta property="og:type" content="website" />
    </Head>

    <div class="min-h-screen scroll-smooth bg-white font-sans text-slate-700">
        <!-- ---------------------------------------------------------------- -->
        <!-- Nav bar                                                          -->
        <!-- ---------------------------------------------------------------- -->
        <header class="sticky top-0 z-40 border-b border-slate-200/70 bg-white/85 backdrop-blur">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6">
                <a href="#top" class="flex items-center gap-2.5">
                    <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-brand-600 to-indigo-400 text-white shadow-md shadow-brand-500/30">
                        <i class="fa-solid fa-bolt text-sm"></i>
                    </div>
                    <span class="text-base font-extrabold tracking-tight text-slate-900">OmniFlow</span>
                </a>

                <nav class="hidden items-center gap-8 md:flex">
                    <a
                        v-for="link in navLinks"
                        :key="link.href"
                        :href="link.href"
                        class="text-sm font-semibold text-slate-600 transition hover:text-brand-700"
                    >
                        {{ link.label }}
                    </a>
                </nav>

                <div class="hidden items-center gap-2 md:flex">
                    <template v-if="isAuthed">
                        <Link
                            :href="route('dashboard')"
                            class="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-bold text-white shadow-sm shadow-brand-500/30 transition hover:bg-brand-700"
                        >
                            Go to dashboard <i class="fa-solid fa-arrow-right text-xs"></i>
                        </Link>
                    </template>
                    <template v-else>
                        <Link
                            v-if="canLogin"
                            :href="route('login')"
                            class="rounded-xl px-4 py-2 text-sm font-semibold text-slate-600 transition hover:text-brand-700"
                        >
                            Log in
                        </Link>
                        <Link
                            :href="primaryCta"
                            class="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-bold text-white shadow-sm shadow-brand-500/30 transition hover:bg-brand-700"
                        >
                            Start free <i class="fa-solid fa-arrow-right text-xs"></i>
                        </Link>
                    </template>
                </div>

                <button
                    type="button"
                    class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-slate-200 text-slate-600 md:hidden"
                    aria-label="Toggle menu"
                    @click="mobileOpen = !mobileOpen"
                >
                    <i :class="mobileOpen ? 'fa-solid fa-xmark' : 'fa-solid fa-bars'"></i>
                </button>
            </div>

            <!-- Mobile menu -->
            <div v-if="mobileOpen" class="border-t border-slate-200 bg-white md:hidden">
                <div class="mx-auto max-w-6xl space-y-1 px-4 py-3">
                    <a
                        v-for="link in navLinks"
                        :key="link.href"
                        :href="link.href"
                        class="block rounded-lg px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50"
                        @click="mobileOpen = false"
                    >
                        {{ link.label }}
                    </a>
                    <div class="grid gap-2 pt-2">
                        <Link
                            v-if="isAuthed"
                            :href="route('dashboard')"
                            class="rounded-xl bg-brand-600 px-4 py-2.5 text-center text-sm font-bold text-white"
                        >
                            Go to dashboard
                        </Link>
                        <template v-else>
                            <Link
                                v-if="canLogin"
                                :href="route('login')"
                                class="rounded-xl border border-slate-200 px-4 py-2.5 text-center text-sm font-semibold text-slate-700"
                            >
                                Log in
                            </Link>
                            <Link
                                :href="primaryCta"
                                class="rounded-xl bg-brand-600 px-4 py-2.5 text-center text-sm font-bold text-white"
                            >
                                Start free
                            </Link>
                        </template>
                    </div>
                </div>
            </div>
        </header>

        <!-- ---------------------------------------------------------------- -->
        <!-- Hero                                                             -->
        <!-- ---------------------------------------------------------------- -->
        <section id="top" class="relative overflow-hidden">
            <div class="pointer-events-none absolute inset-0 -z-10 bg-gradient-to-b from-brand-50/80 via-white to-white"></div>
            <div class="pointer-events-none absolute -right-24 -top-24 -z-10 h-80 w-80 rounded-full bg-brand-200/40 blur-3xl"></div>

            <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:py-24">
                <div>
                    <span class="inline-flex items-center gap-2 rounded-full border border-brand-200 bg-white px-3 py-1 text-xs font-bold text-brand-700 shadow-sm">
                        <span class="h-1.5 w-1.5 rounded-full bg-brand-500"></span>
                        WhatsApp-first customer messaging
                    </span>

                    <h1 class="mt-5 text-4xl font-extrabold leading-[1.1] tracking-tight text-slate-900 sm:text-5xl">
                        Every customer conversation, in
                        <span class="bg-gradient-to-r from-brand-600 to-indigo-400 bg-clip-text text-transparent">one inbox.</span>
                    </h1>

                    <p class="mt-5 max-w-xl text-lg leading-relaxed text-slate-600">
                        OmniFlow unifies WhatsApp, Instagram and Messenger for your team — with a built-in CRM, Meta-approved
                        template broadcasts and simple wallet billing. No more juggling apps.
                    </p>

                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <Link
                            :href="primaryCta"
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-brand-500/30 transition hover:bg-brand-700"
                        >
                            Start free <i class="fa-solid fa-arrow-right text-xs"></i>
                        </Link>
                        <a
                            href="#how-it-works"
                            class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-6 py-3 text-sm font-bold text-slate-700 transition hover:border-brand-300 hover:text-brand-700"
                        >
                            <i class="fa-solid fa-play text-xs"></i> See how it works
                        </a>
                    </div>

                    <p class="mt-4 text-sm text-slate-500">
                        No credit card required · Trial plan included · Cancel anytime
                    </p>

                    <div class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-3">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Connect with</span>
                        <span v-for="channel in channels" :key="channel.label" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-600">
                            <i :class="[channel.icon, channel.tone]"></i> {{ channel.label }}
                        </span>
                    </div>
                </div>

                <!-- Product mock -->
                <div class="relative">
                    <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-2xl shadow-slate-300/40">
                        <div class="flex items-center gap-1.5 pb-3">
                            <span class="h-2.5 w-2.5 rounded-full bg-rose-400"></span>
                            <span class="h-2.5 w-2.5 rounded-full bg-amber-400"></span>
                            <span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
                            <span class="ml-2 text-[11px] font-semibold text-slate-400">OmniFlow · Inbox</span>
                        </div>
                        <div class="grid grid-cols-5 overflow-hidden rounded-xl border border-slate-100">
                            <!-- Thread list -->
                            <div class="col-span-2 space-y-1 border-r border-slate-100 bg-slate-50/60 p-2">
                                <div class="flex items-center gap-2 rounded-lg bg-white p-2 shadow-sm">
                                    <div class="flex h-7 w-7 items-center justify-center rounded-full bg-emerald-100 text-[10px] font-bold text-emerald-700">DS</div>
                                    <div class="min-w-0">
                                        <p class="truncate text-[11px] font-bold text-slate-800">Deepa Sengar</p>
                                        <p class="truncate text-[10px] text-slate-400">Thanks! Ordering now…</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 rounded-lg p-2">
                                    <div class="flex h-7 w-7 items-center justify-center rounded-full bg-rose-100 text-[10px] font-bold text-rose-700">AK</div>
                                    <div class="min-w-0">
                                        <p class="truncate text-[11px] font-semibold text-slate-600">Arjun K.</p>
                                        <p class="truncate text-[10px] text-slate-400">Is this in stock?</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 rounded-lg p-2">
                                    <div class="flex h-7 w-7 items-center justify-center rounded-full bg-blue-100 text-[10px] font-bold text-blue-700">MB</div>
                                    <div class="min-w-0">
                                        <p class="truncate text-[11px] font-semibold text-slate-600">Meera B.</p>
                                        <p class="truncate text-[10px] text-slate-400">Sent my address ✨</p>
                                    </div>
                                </div>
                            </div>
                            <!-- Conversation -->
                            <div class="col-span-3 flex flex-col gap-2 bg-white p-3">
                                <div class="flex items-center gap-2 border-b border-slate-100 pb-2">
                                    <i class="fa-brands fa-whatsapp text-emerald-500"></i>
                                    <span class="text-[11px] font-bold text-slate-700">Deepa Sengar</span>
                                    <span class="ml-auto rounded-full bg-emerald-50 px-2 py-0.5 text-[9px] font-bold text-emerald-700">Opted in</span>
                                </div>
                                <div class="max-w-[85%] rounded-xl rounded-tl-sm bg-slate-100 p-2 text-[10px] leading-relaxed text-slate-600">
                                    Hi Deepa 👋 our Diwali offer is live — enjoy 20% off everything this week.
                                </div>
                                <div class="max-w-[85%] self-end rounded-xl rounded-tr-sm bg-brand-600 p-2 text-[10px] leading-relaxed text-white">
                                    Sounds great! Sending my order now.
                                </div>
                                <div class="mt-auto flex items-center gap-2 rounded-lg border border-slate-100 px-2 py-1.5">
                                    <span class="text-[10px] text-slate-400">Type a reply…</span>
                                    <i class="fa-solid fa-paper-plane ml-auto text-[10px] text-brand-500"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="absolute -bottom-5 -left-4 hidden items-center gap-3 rounded-xl border border-slate-100 bg-white px-4 py-3 shadow-xl sm:flex">
                        <i class="fa-solid fa-chart-line text-brand-600"></i>
                        <div>
                            <p class="text-sm font-extrabold text-slate-900">+38%</p>
                            <p class="text-[11px] text-slate-400">faster replies</p>
                        </div>
                    </div>
                    <div class="absolute -right-3 -top-5 hidden items-center gap-2 rounded-xl border border-slate-100 bg-white px-3 py-2 shadow-xl sm:flex">
                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                        <p class="text-[11px] font-bold text-slate-600">Broadcast delivered</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ---------------------------------------------------------------- -->
        <!-- Features                                                          -->
        <!-- ---------------------------------------------------------------- -->
        <section id="features" class="scroll-mt-20 border-t border-slate-100 bg-slate-50/60 py-20">
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                <div class="mx-auto max-w-2xl text-center">
                    <span class="text-xs font-bold uppercase tracking-widest text-brand-600">Everything in one place</span>
                    <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">
                        Built to run the whole customer conversation
                    </h2>
                    <p class="mt-4 text-lg text-slate-600">
                        From the first hello to the follow-up broadcast — OmniFlow covers the messaging stack your team needs.
                    </p>
                </div>

                <div class="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="feature in features"
                        :key="feature.title"
                        class="group rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-lg hover:shadow-brand-500/5"
                    >
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600 transition group-hover:bg-brand-600 group-hover:text-white">
                            <i :class="feature.icon"></i>
                        </div>
                        <h3 class="mt-4 text-lg font-bold text-slate-900">{{ feature.title }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ feature.body }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ---------------------------------------------------------------- -->
        <!-- How it works                                                      -->
        <!-- ---------------------------------------------------------------- -->
        <section id="how-it-works" class="scroll-mt-20 py-20">
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                <div class="mx-auto max-w-2xl text-center">
                    <span class="text-xs font-bold uppercase tracking-widest text-brand-600">Up and running in minutes</span>
                    <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">How it works</h2>
                    <p class="mt-4 text-lg text-slate-600">Three steps from sign-up to your first broadcast.</p>
                </div>

                <div class="mt-14 grid gap-8 md:grid-cols-3">
                    <div v-for="(step, index) in steps" :key="step.title" class="relative">
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-brand-600 to-indigo-400 text-lg font-extrabold text-white shadow-lg shadow-brand-500/30">
                            {{ index + 1 }}
                        </div>
                        <h3 class="mt-5 text-lg font-bold text-slate-900">{{ step.title }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ step.body }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ---------------------------------------------------------------- -->
        <!-- Pricing                                                           -->
        <!-- ---------------------------------------------------------------- -->
        <section id="pricing" class="scroll-mt-20 border-t border-slate-100 bg-slate-50/60 py-20">
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                <div class="mx-auto max-w-2xl text-center">
                    <span class="text-xs font-bold uppercase tracking-widest text-brand-600">Simple, transparent pricing</span>
                    <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">Plans that scale with you</h2>
                    <p class="mt-4 text-lg text-slate-600">
                        Flat monthly subscription · prices exclude GST · top up a wallet for message costs.
                    </p>
                </div>

                <div class="mt-14 grid gap-6 lg:grid-cols-3">
                    <div
                        v-for="plan in pricing"
                        :key="plan.slug"
                        class="relative flex flex-col rounded-2xl border bg-white p-7 shadow-sm"
                        :class="plan.slug === highlightSlug ? 'border-brand-300 shadow-xl shadow-brand-500/10 lg:-mt-4 lg:mb-4' : 'border-slate-200'"
                    >
                        <span
                            v-if="plan.slug === highlightSlug"
                            class="absolute -top-3 left-7 rounded-full bg-brand-600 px-3 py-1 text-[11px] font-bold uppercase tracking-wide text-white shadow"
                        >
                            Most popular
                        </span>

                        <h3 class="text-lg font-extrabold text-slate-900">{{ plan.name }}</h3>
                        <p class="mt-4 flex items-baseline gap-1">
                            <span class="text-4xl font-extrabold tracking-tight text-slate-900">{{ money(plan.priceRupees) }}</span>
                            <span class="text-sm font-semibold text-slate-400">/ month</span>
                        </p>

                        <ul class="mt-6 flex-1 space-y-3 text-sm text-slate-600">
                            <li class="flex items-center gap-2">
                                <i class="fa-solid fa-check text-brand-600"></i>
                                {{ plan.contacts.toLocaleString() }} monthly active contacts
                            </li>
                            <li class="flex items-center gap-2">
                                <i class="fa-solid fa-check text-brand-600"></i>
                                {{ plan.instagram }} Instagram account{{ plan.instagram === 1 ? '' : 's' }}
                            </li>
                            <li class="flex items-center gap-2">
                                <i class="fa-solid fa-check text-brand-600"></i>
                                {{ plan.seats }} agent seat{{ plan.seats === 1 ? '' : 's' }}
                            </li>
                            <li class="flex items-center gap-2">
                                <i class="fa-solid fa-check text-brand-600"></i>
                                Unlimited WhatsApp & Messenger channels
                            </li>
                        </ul>

                        <Link
                            :href="primaryCta"
                            class="mt-7 inline-flex items-center justify-center gap-2 rounded-xl px-5 py-3 text-sm font-bold transition"
                            :class="plan.slug === highlightSlug
                                ? 'bg-brand-600 text-white shadow-lg shadow-brand-500/30 hover:bg-brand-700'
                                : 'border border-slate-200 text-slate-700 hover:border-brand-300 hover:text-brand-700'"
                        >
                            Get started <i class="fa-solid fa-arrow-right text-xs"></i>
                        </Link>
                    </div>
                </div>

                <p class="mt-8 text-center text-sm text-slate-500">
                    Need something custom? <a href="#faq" class="font-semibold text-brand-700 hover:underline">Talk to us</a>.
                </p>
            </div>
        </section>

        <!-- ---------------------------------------------------------------- -->
        <!-- FAQ                                                               -->
        <!-- ---------------------------------------------------------------- -->
        <section id="faq" class="scroll-mt-20 py-20">
            <div class="mx-auto max-w-3xl px-4 sm:px-6">
                <div class="text-center">
                    <span class="text-xs font-bold uppercase tracking-widest text-brand-600">Good to know</span>
                    <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">Frequently asked questions</h2>
                </div>

                <div class="mt-12 space-y-3">
                    <details
                        v-for="item in faqs"
                        :key="item.q"
                        class="group rounded-xl border border-slate-200 bg-white p-5 shadow-sm open:border-brand-200"
                    >
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-sm font-bold text-slate-800">
                            {{ item.q }}
                            <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition group-open:rotate-180"></i>
                        </summary>
                        <p class="mt-3 text-sm leading-relaxed text-slate-600">{{ item.a }}</p>
                    </details>
                </div>
            </div>
        </section>

        <!-- ---------------------------------------------------------------- -->
        <!-- Final CTA                                                         -->
        <!-- ---------------------------------------------------------------- -->
        <section class="px-4 pb-20 sm:px-6">
            <div class="mx-auto max-w-6xl overflow-hidden rounded-3xl bg-gradient-to-br from-brand-700 via-brand-600 to-indigo-500 px-8 py-14 text-center shadow-xl shadow-brand-500/30">
                <h2 class="text-3xl font-extrabold tracking-tight text-white sm:text-4xl">
                    Ready to bring your conversations together?
                </h2>
                <p class="mx-auto mt-4 max-w-xl text-lg text-brand-50">
                    Set up your workspace and connect your first channel in minutes.
                </p>
                <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                    <Link
                        :href="primaryCta"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-white px-6 py-3 text-sm font-bold text-brand-700 shadow-lg transition hover:bg-brand-50"
                    >
                        Start free <i class="fa-solid fa-arrow-right text-xs"></i>
                    </Link>
                    <Link
                        v-if="canLogin"
                        :href="route('login')"
                        class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/40 px-6 py-3 text-sm font-bold text-white transition hover:bg-white/10"
                    >
                        Log in
                    </Link>
                </div>
            </div>
        </section>

        <!-- ---------------------------------------------------------------- -->
        <!-- Footer                                                            -->
        <!-- ---------------------------------------------------------------- -->
        <footer class="border-t border-slate-200 bg-white">
            <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-6 px-4 py-10 sm:px-6 md:flex-row">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-brand-600 to-indigo-400 text-white">
                        <i class="fa-solid fa-bolt text-xs"></i>
                    </div>
                    <div>
                        <p class="text-sm font-extrabold tracking-tight text-slate-900">OmniFlow</p>
                        <p class="text-[11px] text-slate-400">Unified customer messaging</p>
                    </div>
                </div>

                <nav class="flex flex-wrap items-center justify-center gap-x-6 gap-y-2">
                    <a v-for="link in navLinks" :key="link.href" :href="link.href" class="text-sm font-semibold text-slate-500 transition hover:text-brand-700">
                        {{ link.label }}
                    </a>
                    <Link v-if="canLogin" :href="route('login')" class="text-sm font-semibold text-slate-500 transition hover:text-brand-700">
                        Log in
                    </Link>
                </nav>

                <p class="text-xs text-slate-400">© {{ new Date().getFullYear() }} OmniFlow. All rights reserved.</p>
            </div>
        </footer>
    </div>
</template>
