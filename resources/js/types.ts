export type StatKey = 'str' | 'dex' | 'int' | 'vit' | 'luk';
export type Stats = Record<StatKey, number>;
export type Rarity = 'common' | 'uncommon' | 'rare' | 'epic' | 'legendary';
export type Outcome = 'cleared' | 'retreated' | 'defeated' | 'rested' | 'blocked_injured' | 'order_rejected' | 'champion' | 'eliminated';

export type PremiumCategory = 'title' | 'wings' | 'aura' | 'pet' | 'frame' | 'bundle';
export type PremiumTier = 'rare' | 'epic' | 'legendary' | 'mythic';

export interface PremiumPiece {
    code: string;
    name: string;
    category: PremiumCategory;
    tier: PremiumTier;
    glyph: string;
    style: string;
    description: string | null;
    price_cents: number;
    price: string;
    bonuses: Record<string, number>;
    power: number;
    grant_gold: number;
}

export interface Appearance {
    title: PremiumPiece | null;
    gear: PremiumPiece[];
}

export interface CollectionPiece extends PremiumPiece {
    owned: boolean;
    active: boolean;
    worn: boolean;
}

export interface Collection {
    pieces: CollectionPiece[];
    bonus: Record<string, number>;
    power: number;
}

export interface Hero {
    id: number;
    name: string;
    title: PremiumPiece | null;
    cash: number;
    level: number;
    power: number;
    xp: number;
    xp_to_next: number | null;
    gold: number;
    unspent: number;
    injury_severity: number;
    injury_runs_left: number;
    unread: number;
    row_version: number;
}

export interface RunStatus {
    running: boolean;
    kind: 'expedition' | 'arena' | null;
    last: {
        id: number;
        status: string;
        started_at: string | null;
        finished_at: string | null;
        characters_done: number;
        characters_total: number;
    } | null;
}

export interface SharedProps {
    [key: string]: unknown;
    appName: string;
    displayTimezone: string;
    auth: { account: { id: number; username: string; is_admin: boolean } | null };
    hero: Hero | null;
    run: RunStatus;
    flash: {
        success: string | null;
        error: string | null;
        openedChest: OpenedChest | null;
        forged: ForgeResult | null;
    };
    errors: Record<string, string>;
}

export interface SealedChest {
    id: number;
    place: string;
    rolls: number;
}

export interface OpenedChestItem {
    id: number;
    name: string;
    rarity: Rarity;
    item_level: number;
    slot: string;
}

export interface OpenedChest {
    place: string;
    items: OpenedChestItem[];
    sold: { name: string; sell_value: number }[];
}

export interface ForgeResult {
    outcome: 'fail' | 'craft' | 'masterpiece';
    message: string;
    gold: number;
    item: OpenedChestItem | null;
}

export interface Affix {
    stat_key: string;
    value: number;
    name: string;
    kind: 'prefix' | 'suffix';
}

export interface Item {
    id: number;
    name: string;
    template_name: string;
    code: string;
    rarity: Rarity;
    slot: string;
    template_slot: string;
    equip_slot: string | null;
    item_type: string;
    scaling_stat: StatKey | null;
    two_handed: boolean;
    item_level: number;
    req_level: number;
    power: number;
    armor: number;
    mres: number;
    sell_value: number;
    source: string;
    implicit: Record<string, number>;
    affixes: Affix[];
    description: string | null;
}

export interface PackItem extends Item {
    target_slot: string;
    can_equip: boolean;
    equip_note: string | null;
    delta: Record<string, number>;
    feed: number;
}

export interface ForgeQuote {
    ok: boolean;
    reason: string | null;
    gold: number;
    chances: { fail: number; craft: number; masterpiece: number } | null;
    slot: string | null;
    left_id: number;
    right_id: number;
}

export interface Consumable {
    id: number;
    code: string;
    name: string;
    quantity: number;
    belt_qty: number;
    effect_type: string;
    effect_value: number;
    use_trigger: string;
    req_level: number;
    sell_price: number;
    description: string | null;
}

export interface Derived {
    hp: number;
    attack: number;
    defense: number;
    mres: number;
    crit: number;
    crit_mult?: number;
    dodge: number;
    speed: number;
    regen?: number;
    luck?: number;
    gold_find?: number;
    lifesteal?: number;
    penetration?: number;
    dmg_reduction?: number;
    xp_mult?: number;
    damage_type: 'physical' | 'magic';
}

export interface NextDestination {
    label: string;
    kind: 'blocked' | 'order' | 'repeat' | 'rest' | 'auto' | 'auto_best';
}

export interface Order {
    id: number;
    place_id: number;
    name: string;
    code: string;
    place_type: string;
    submitted_at: string;
}

export interface TripRow {
    id: number;
    run_id: number;
    outcome: Outcome;
    xp: number;
    gold: number;
    level_before: number;
    level_after: number;
    hp_pct: number;
    encounters_won: number;
    place: string;
    place_code: string;
    place_type: string;
    created_at: string;
}

export interface MailRow {
    id: number;
    subject: string;
    is_read: boolean;
    created_at: string;
    outcome: Outcome | null;
    xp: number | null;
    gold: number | null;
    items: number;
    level_up: boolean;
    trip_id: number | null;
}

export type LogEvent = {
    r?: number;
    ev: 'hit' | 'dodge' | 'potion' | 'potion_topup' | 'flee' | 'flee_fail' | 'left_early' | 'loot' | 'mat';
    name?: string;
    qty?: number;
    rarity?: Rarity;
    a?: string;
    t?: string;
    dmg?: number;
    crit?: boolean;
    thp?: number;
    heal?: number;
    ahp?: number;
    code?: string;
    p?: number;
};

export interface Unit {
    name: string;
    label: string;
    base: string;
    code: string | null;
    level: number | null;
    hp: number;
    is_boss: boolean;
}

export interface Encounter {
    seq: number;
    is_boss: boolean;
    result: 'won' | 'lost' | 'fled' | 'skipped' | string;
    rounds: number;
    damage_dealt: number;
    damage_taken: number;
    hp_before: number;
    hp_after: number;
    xp: number;
    gold: number;
    units: Unit[];
    log: LogEvent[];
}

export interface TripLoot {
    id: number;
    name: string;
    rarity: Rarity;
    item_level: number;
    kept: boolean;
    status: string;
    source: string;
    encounter_seq: number | null;
}

export interface Trip {
    id: number;
    run_id: number;
    hero: string;
    place: { name: string; code: string; type: string; rec: [number, number] };
    outcome: Outcome;
    max_hp: number;
    hp_end: number;
    xp: number;
    gold: number;
    level_before: number;
    level_after: number;
    encounters_planned: number;
    encounters_won: number;
    boss_encountered: boolean;
    boss_killed: boolean;
    created_at: string;
    derived: Derived | null;
    buffs: string[];
    consumables_used: Record<string, number> | unknown[];
    encounters: Encounter[];
    loot: TripLoot[];
    chest: { status: 'sealed' | 'opened'; rolls: number } | null;
    mail_id: number | null;
}
