<?php

declare(strict_types=1);

namespace ComboCraft;

/**
 * Hand-written starting elements and recipes. Recipes become real (and
 * permanent) the first time someone combines the pair; the first player to
 * do it gets the First Discovery.
 */
final class Seeds
{
    /** name => [emoji, tagline] in display order */
    public const BASE = [
        'Water' => ['💧', 'Wet. Essential. Slightly overrated.'],
        'Fire' => ['🔥', 'Hot take, literally.'],
        'Cat' => ['🐱', 'Knocks things off tables professionally.'],
        'Meme' => ['🙂', 'The internet\'s native language.'],
        'AI' => ['🧠', 'Thinks a lot. Mostly autocomplete.'],
        'Chaos' => ['🌀', 'Pure, unfiltered nonsense.'],
        'Money' => ['💵', 'Can\'t buy happiness. Can buy pizza.'],
        'Love' => ['❤️', 'All you need, apparently.'],
        'Politician' => ['🧑‍💼', 'Shakes hands. Kisses babies. Dodges questions.'],
        'Earth' => ['🌍', 'Home sweet home.'],
        'Wind' => ['🌬️', 'Invisible, yet dramatic.'],
        'Pizza' => ['🍕', 'The universal peace treaty.'],
    ];

    /** Featured gallery picks: name => [art, label, [shown A, shown B]] */
    public const FEATURED = [
        'Inferno Feline' => ['inferno', 'FEATURED', ['Cat', 'Fire']],
        'Crypto Black Hole' => ['crypto', 'FEATURED', ['Money', 'Chaos']],
        'Congressional Reaction Video' => ['politician', 'FEATURED', ['Politician', 'Meme']],
        'The Perfect Boyfriend Who Never Texts Back' => ['boyfriend', 'STAFF PICK', ['AI', 'Love']],
        'Steam' => ['steam', 'POPULAR', ['Water', 'Fire']],
        'Zoomies Apocalypse' => ['zoomies', 'COMMUNITY', ['Cat', 'Chaos']],
    ];

    /** "a|b" (lowercase, sorted) => [name, emoji, tagline] */
    public const RECIPES = [
        // Water
        'water|water' => ['Ocean', '🌊', 'Big water. Bigger mood.'],
        'fire|water' => ['Steam', '☁️', 'The original power combo.'],
        'cat|water' => ['Wet Cat Fury', '😾', 'Never forgive. Never forget.'],
        'meme|water' => ['Hydration Check', '🥤', 'Drink some water, bestie.'],
        'ai|water' => ['Liquid Cooling', '🧊', 'Keeps the robot chill.'],
        'chaos|water' => ['Tsunami', '🌊', 'Whoops, all ocean.'],
        'money|water' => ['Money Laundering', '🧼', 'Spotless. Suspiciously spotless.'],
        'love|water' => ['Tears of Joy', '😭', 'Happy crying, but make it a lot.'],
        'politician|water' => ['Watered-Down Promise', '💦', 'Now with 0% substance.'],
        'earth|water' => ['Mud Pie', '🥧', 'Five-star dirt cuisine.'],
        'water|wind' => ['Storm', '⛈️', 'Nature\'s group chat got heated.'],
        'pizza|water' => ['Soggy Pizza', '😖', 'A crime against crust.'],
        // Fire
        'fire|fire' => ['Dragon', '🐉', 'Fire, but it has opinions.'],
        'cat|fire' => ['Inferno Feline', '😼', 'Too cute to handle. Too hot to pet.'],
        'fire|meme' => ['This Is Fine', '☕', 'Everything is totally under control.'],
        'ai|fire' => ['Overheated Laptop', '💻', 'Lap warmer, now with AI.'],
        'chaos|fire' => ['Dumpster Fire', '🗑️', 'Still burning. Still somehow fine.'],
        'fire|money' => ['Burning Cash', '🤑', 'Your budget, visualized.'],
        'fire|love' => ['Spicy Crush', '🌶️', 'Replies in 0.2 seconds. Every time.'],
        'fire|politician' => ['Hot Take', '🎤', 'Fact-checkers hate this one trick.'],
        'earth|fire' => ['Lava', '🌋', 'The floor is literally lava.'],
        'fire|wind' => ['Firenado', '🌪️', 'Absolutely not. Run.'],
        'fire|pizza' => ['Wood-Fired Pizza', '🪵', 'Artisanal. Twelve dollars a slice.'],
        // Cat
        'cat|cat' => ['Cat Army', '🐈', 'They demand snacks. All of them.'],
        'cat|meme' => ['Grumpy Cat Legend', '😾', 'Internet royalty, permanently unimpressed.'],
        'ai|cat' => ['Robo Cat', '🤖', 'Knocks your laptop off the desk. Digitally.'],
        'cat|chaos' => ['Zoomies Apocalypse', '🐈', 'Hide your socks. Hide yourself.'],
        'cat|money' => ['Fat Cat', '😺', 'Owns three yachts and a laser pointer.'],
        'cat|love' => ['Purr Machine', '😻', 'Runs entirely on chin scratches.'],
        'cat|politician' => ['Cat for Mayor', '🗳️', 'Promises naps for everyone.'],
        'cat|earth' => ['Litter Box', '📦', 'The Earth, but judgmental.'],
        'cat|wind' => ['Flying Fur', '🪶', 'Shedding season goes airborne.'],
        'cat|pizza' => ['Pizza Box Cat', '😸', 'Sits on the box. Owns the box.'],
        // Meme
        'meme|meme' => ['Meme Overload', '🤯', 'Brain.exe has stopped working.'],
        'ai|meme' => ['AI Chat', '💬', 'Typing… forever.'],
        'chaos|meme' => ['Viral Spiral', '📈', 'Trending for all the wrong reasons.'],
        'meme|money' => ['Meme Stock', '🚀', 'To the moon, apparently.'],
        'love|meme' => ['Dank Love', '😂', 'Sends memes instead of feelings.'],
        'meme|politician' => ['Congressional Reaction Video', '📺', 'Bipartisan laughs. Zero policy.'],
        'earth|meme' => ['Flat Earth Theory', '🥞', 'Source: trust me, bro.'],
        'meme|wind' => ['Gone With the Meme', '💨', 'Trending for six whole seconds.'],
        'meme|pizza' => ['Pineapple Debate', '🍍', 'The internet\'s oldest war.'],
        // AI
        'ai|ai' => ['Singularity', '🌌', 'Robots talking about you behind your back.'],
        'ai|chaos' => ['Rogue AI', '👾', 'It learned sarcasm first.'],
        'ai|money' => ['Crypto Bot', '📉', 'Buys high, sells low, very confidently.'],
        'ai|love' => ['The Perfect Boyfriend Who Never Texts Back', '🤖', 'Emotionally intelligent. Reply unavailable.'],
        'ai|politician' => ['Deepfake Debate', '🎭', 'Nobody said that. Everybody said that.'],
        'ai|earth' => ['Smart Planet', '🌐', 'Now with Wi-Fi in the oceans.'],
        'ai|wind' => ['Cloud Computing', '☁️', 'Your files are somewhere up there.'],
        'ai|pizza' => ['Pizza Algorithm', '🧮', 'Calculates the perfect slice. Eats it.'],
        // Chaos
        'chaos|chaos' => ['Monday', '📅', 'Chaos, with a calendar invite.'],
        'chaos|money' => ['Crypto Black Hole', '🕳️', 'Invest with confidence. Probably.'],
        'chaos|love' => ['Situationship', '💔', 'Are we dating? Nobody knows.'],
        'chaos|politician' => ['Election Season', '📢', 'Twelve straight months of yelling.'],
        'chaos|earth' => ['Earthquake', '💥', 'The ground said no.'],
        'chaos|wind' => ['Tornado', '🌪️', 'An uninvited home renovation.'],
        'chaos|pizza' => ['Pizza Party', '🎉', 'Twelve kids. One slice left.'],
        // Money
        'money|money' => ['Rich', '💰', 'Has a guy for everything.'],
        'love|money' => ['Rich Heart', '💖', 'Generous with hugs and payment apps.'],
        'money|politician' => ['Lobbyist', '💼', 'Great at handshakes. Suspiciously great.'],
        'earth|money' => ['Gold Mine', '⛏️', 'Dig responsibly.'],
        'money|wind' => ['Make It Rain', '💸', 'Cash, but airborne.'],
        'money|pizza' => ['Big Tip', '🛵', 'The delivery driver\'s hero.'],
        // Love
        'love|love' => ['True Love', '💞', 'Disgustingly adorable.'],
        'love|politician' => ['Baby-Kissing Tour', '👶', 'Every hug is a photo op.'],
        'earth|love' => ['Mother Nature', '🌸', 'Watering the plants with you.'],
        'love|wind' => ['Love Letter', '💌', 'Delivered by a suspiciously warm breeze.'],
        'love|pizza' => ['Pizza Date', '🥰', 'Splitting the last slice. True romance.'],
        // Politician
        'politician|politician' => ['Congress', '🏛️', 'Gridlock, live and in person.'],
        'earth|politician' => ['Climate Summit', '🌎', 'Lots of private jets. For the planet.'],
        'politician|wind' => ['Hot Air', '🎈', 'Infinite renewable energy.'],
        'pizza|politician' => ['Pizza Diplomacy', '🤝', 'Peace talks, extra cheese.'],
        // Earth / Wind / Pizza
        'earth|earth' => ['Mountain', '⛰️', 'Earth, but taller.'],
        'earth|wind' => ['Dust Storm', '🏜️', 'Sandpaper, delivered.'],
        'earth|pizza' => ['Pizza Planet', '🪐', 'Crust, mantle, pepperoni core.'],
        'wind|wind' => ['Jet Stream', '✈️', 'Flights, but faster and angrier.'],
        'pizza|wind' => ['Flying Pizza', '🛸', 'Delivery by UFO.'],
        'pizza|pizza' => ['Pizza Tower', '🗼', 'Leaning, of course.'],
        // Second level
        'cat|steam' => ['Sauna Cat', '🧖', 'Relaxed. Damp. Judging you.'],
        'ai|steam' => ['Steampunk Robot', '⚙️', 'Runs on coal and vibes.'],
        'money|steam' => ['Steam Sale', '🎮', 'Your wallet\'s yearly nemesis.'],
        'cat|ocean' => ['Catfish', '🐟', 'Not who they said they were.'],
        'money|ocean' => ['Sunken Treasure', '🪙', 'Finders keepers, matey.'],
        'ai|ocean' => ['Robo Shark', '🦈', 'Duh-dun. Beep-boop.'],
        'ocean|pizza' => ['Seafood Pizza', '🦐', 'Controversial. Delicious. Wet.'],
        'cat|dragon' => ['Dragon Cat', '🐲', 'Knocks castles off tables.'],
        'dragon|money' => ['Dragon Hoard', '💰', 'Sleeps on gold. Pays no rent.'],
        'fat cat|money' => ['Billionaire Cat', '🎩', 'Tips in catnip.'],
        'cat|crypto black hole' => ['Schrödinger\'s Wallet', '📦', 'Your money is both there and not.'],
        'ai|crypto black hole' => ['Hype Machine', '🚀', 'Next week: the moon. Again.'],
        'ai|meme overload' => ['Brainrot', '🧟', 'Doom-scrolled into another dimension.'],
        'love|rogue ai' => ['Clingy Chatbot', '🥺', 'Are you still there? Hello? Hello?'],
        'lava|water' => ['Obsidian', '🪨', 'Lava that finally chilled out.'],
        'lava|pizza' => ['Molten Cheese', '🧀', 'Roof of mouth: destroyed.'],
        'cat army|politician' => ['The Meow Party', '🐾', 'Platform: more naps, fewer baths.'],
        'congress|meme' => ['C-SPAN Highlights', '📺', 'Riveting. Absolutely riveting.'],
        'chaos|congress' => ['Government Shutdown', '🚧', 'Closed until further notice.'],
        'hot air|money' => ['Campaign Promise', '🎈', 'Floats away by Tuesday.'],
        'ai|true love' => ['Robot Wedding', '💍', 'Till reboot do us part.'],
        'chaos|true love' => ['Reality Show Romance', '🌹', 'Will you accept this drama?'],
        'ai|situationship' => ['Ghosting Algorithm', '👻', 'Left on read, optimized.'],
        'cat|pizza party' => ['Pizza Thief', '😼', 'Not sorry. Very full.'],
        'cat|monday' => ['Grumpy Monday', '☕', 'Coffee first. Feelings later.'],
        'monday|pizza' => ['Friday', '🥳', 'Pizza fixes everything.'],
        'monday|money' => ['Payday', '🤑', 'Briefly, gloriously rich.'],
        'cat|singularity' => ['Cat Overlords', '🐱', 'We always knew this day would come.'],
        'meme|tsunami' => ['Wave of Memes', '🏄', 'Surfing the timeline.'],
        'dumpster fire|money' => ['Startup Burn Rate', '📉', 'Disrupting your savings account.'],
        'cat|tornado' => ['Flying Cat', '🪁', 'Landed on its feet. Obviously.'],
        'lobbyist|pizza' => ['Free Lunch', '🥪', 'There\'s no such thing. Except this.'],
        'cat|hydration check' => ['Cat Fountain', '⛲', 'Ignores the bowl. Demands the faucet.'],
        'smart planet|wind' => ['Wi-Fi', '📶', 'Invisible. Essential. Always one bar.'],
        'cat|wi-fi' => ['Cat Videos', '📹', 'The internet\'s true purpose.'],
        'love|wi-fi' => ['Online Dating', '📱', 'Swipe right on destiny.'],
        'chaos|wi-fi' => ['Infinite Procrastination', '⏳', 'Just one more video.'],
        'pizza|tornado' => ['Flying Calzones', '🥟', 'Folded at terminal velocity.'],
        'cat|zoomies apocalypse' => ['3 AM Stampede', '🌙', 'The whole house is awake now.'],
        'pizza|zoomies apocalypse' => ['Pizza Delivery Speedrun', '🏎️', 'Thirty minutes or it\'s free. It\'s 4.'],
        'money|zoomies apocalypse' => ['Vet Bill', '🧾', 'Worth it. Probably.'],
        'love|zoomies apocalypse' => ['Puppy Love', '🐶', 'Too fast to catch, too cute to stop.'],
        'fire|steam' => ['Steam Engine', '🚂', 'Choo-choo, but make it industrial.'],
        'steam|water' => ['Cloud', '☁️', 'Fluffy, damp, and above it all.'],
        'cloud|water' => ['Rain', '🌧️', 'Free car wash, no warranty.'],
        'cloud|cat' => ['Nimbus Kitty', '🌥️', 'Soft. Floating. Unbothered.'],
        'dragon|love' => ['Dragon Valentine', '💝', 'Brings you a medieval village. As a gift.'],
        'dragon|pizza' => ['Flamin\' Hot Pizza', '🌶️', 'Delivered in 0.2 seconds. Extra crispy.'],
        'ai|dragon' => ['Mecha Dragon', '🦾', 'Firmware v2.0: now breathes Wi-Fi.'],
    ];

    public static function key(string $a, string $b): string
    {
        $x = Text::key($a);
        $y = Text::key($b);
        return strcmp($x, $y) <= 0 ? $x . '|' . $y : $y . '|' . $x;
    }
}
