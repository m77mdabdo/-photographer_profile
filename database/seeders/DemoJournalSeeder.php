<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Articles\SyncArticleMedia;
use App\Enums\MediaStatus;
use App\Enums\PublishStatus;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Media;
use App\Models\Tag;
use Illuminate\Database\Seeder;

/**
 * Six pieces of writing, at the length a studio actually writes at.
 *
 * WHY THE TEXT IS REAL
 *
 * Lorem ipsum in a journal proves the template renders and nothing else. It
 * cannot show whether a 900-word piece holds its measure, whether the reading
 * estimate is plausible, whether a pull quote sits right, or whether the index
 * reads as a body of writing rather than a list of stubs. So these are written
 * out: different lengths, different shapes, one of them deliberately short,
 * because studios have those too and the templates have to hold at that
 * length.
 *
 * THE LIMIT: this is the studio's own voice, invented for a demo site. It
 * quotes no real photographer, names no real client, and makes no claim about
 * any real person's practice.
 *
 * IDEMPOTENT, and safe in the way Task 24 established: keyed on slug, and
 * anything the studio has edited or binned is left alone.
 * See Concerns\ProtectsEditedContent.
 */
class DemoJournalSeeder extends Seeder
{
    use Concerns\ProtectsEditedContent;

    /**
     * @var array<string, string>
     */
    private const CATEGORIES = [
        'Process' => 'How the pictures actually get made, start to finish.',
        'Printing' => 'The darkroom, the paper, and the arguments about both.',
        'Equipment' => 'Cameras and lenses that have earned their place, and some that have not.',
        'Commissions' => 'Notes from jobs, and what they taught.',
    ];

    public function run(): void
    {
        /*
         * DEMO MEDIA ONLY.
         *
         * Without the is_demo condition this attaches a real client's
         * photograph to an invented article — which is the exact leak an
         * earlier task fixed in the other two seeders, and which ArchiveTest
         * caught here within minutes of this file being written.
         */
        $media = Media::query()
            ->where('status', MediaStatus::Ready)
            ->where('is_demo', true)
            ->orderBy('id')
            ->get();

        if ($media->isEmpty()) {
            $this->command?->warn('No processed demo media. Run media:fetch-demo first — the journal seeds without images.');
        }

        $categories = collect(self::CATEGORIES)->mapWithKeys(
            fn (string $description, string $name): array => [$name => $this->category($name, $description)]
        );

        foreach ($this->pieces() as $index => $piece) {
            $article = Article::withTrashed()->firstOrNew(['slug' => $piece['slug']]);

            // Leave alone anything the studio has taken over or binned.
            if (! $this->mayOverwrite($article)) {
                continue;
            }

            $article->forceFill([
                'slug' => $piece['slug'],
                'title' => $piece['title'],
                'subtitle' => $piece['subtitle'],
                'excerpt' => $piece['excerpt'],

                // body_html is written by the model's saving hook, from the
                // sanitiser. Never set here.
                'body' => $piece['body'],

                'article_category_id' => $categories[$piece['category']]->id,
                'featured_media_id' => $media->isNotEmpty()
                    ? $media[($index * 5 + 2) % $media->count()]->id
                    : null,
                'status' => PublishStatus::Published,
                'is_demo' => true,
            ]);

            // Newest first, a fortnight apart, so the index has a real shape.
            $article->published_at ??= now()->subDays(11 + $index * 15);
            $article->save();

            $article->tags()->sync(
                collect($piece['tags'])->map(fn (string $name): int => Tag::named($name)->id)->all()
            );

            app(SyncArticleMedia::class)($article);
        }

        $this->command?->info(sprintf(
            'Journal ready: %d pieces across %d subjects.',
            count($this->pieces()),
            $categories->count()
        ));
    }

    private function category(string $name, string $description): ArticleCategory
    {
        $slug = \Illuminate\Support\Str::slug($name);

        $category = ArticleCategory::query()->where('slug', $slug)->first();

        if ($category !== null) {
            return $category;
        }

        $category = new ArticleCategory(['name' => $name, 'description' => $description]);
        $category->slug = $slug;
        $category->save();

        return $category;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pieces(): array
    {
        return [
            [
                'slug' => 'the-edit-is-the-work',
                'title' => 'The edit is the work',
                'subtitle' => 'Four hundred frames, twenty-two pictures, and the part nobody sees.',
                'category' => 'Process',
                'tags' => ['editing', 'process'],
                'excerpt' => 'A commission produces between two and four hundred frames and delivers between twelve and thirty. Everything interesting happens in the gap.',
                'body' => <<<'MD'
                    A commission usually produces between two and four hundred frames. It delivers between twelve and thirty. Almost everyone I have worked with assumes the difference is quality control — that I am throwing away the ones where somebody blinked, or the focus went, or the light did something I did not want. That is perhaps thirty frames out of four hundred. The rest of the gap is something else entirely.

                    ## Subtraction as an argument

                    A set of twenty-two pictures says something a set of eighty cannot, and it is not "here are the best eighty-two per cent". Twenty-two pictures make a claim: *this is what the place was like*. Eighty pictures make a different and much weaker claim, which is *I was there for two days and here is what happened*. The second one is a record. The first one is a position, and a position can be wrong, which is exactly what makes it worth having.

                    So the edit is not a filter. It is where the work actually gets made. I have a rough shape in mind on the day — this is the light I came for, this is the frame the whole thing hangs on — but I am almost always wrong about which frame that is, and I only find out a week later with the contact sheets on the table.

                    ## Why a week

                    Because the day is still in your eyes. If I edit on the evening of a shoot I choose the pictures I remember taking: the one where something difficult finally worked, the one where the subject said something funny, the one that cost me an hour of standing in a doorway. None of that is visible in the photograph. A stranger looking at the print has no idea which one was hard, and would not care if they did.

                    A week is long enough that I have forgotten the effort and can see what is actually on the paper. Two weeks is better. On a long job I have left a set for a month, and every single time the edit came out shorter.

                    > The ones you fight for are almost never the ones that survive. The ones that survive are usually the frame before or the frame after, taken when you had stopped trying.

                    ## What comes out first

                    Duplicates go first, and there are always more than you think. Four frames of the same gesture, a quarter of a second apart, and three of them are nearly right. Nearly right is the enemy: it is the category that makes an edit long, because each one has something the others do not and none of them is the picture.

                    Then the ones that are only interesting because of what they are of. A photograph of an unusual thing is not the same as an unusual photograph, and a set that leans on its subject falls apart the moment somebody encounters the subject anywhere else.

                    Then — and this is the hard one — the ones I like. Not the ones that work; the ones I like. There is usually one frame in every job that I am attached to for reasons that have nothing to do with the job, and it is almost always the one making the sequence limp.

                    ## The last pass

                    The final cut is done in a running order, not as a pile. Pictures change each other. A quiet frame after a loud one reads as relief; the same frame after three other quiet ones reads as filler. I lay them out, walk away, come back and remove whatever I skipped past on the way through.

                    When nothing gets skipped, it is finished. That is the whole method, and it has never once produced the set I expected on the day.
                    MD,
            ],
            [
                'slug' => 'available-dark',
                'title' => 'Available dark',
                'subtitle' => 'Everyone talks about available light. The interesting decisions are about the other thing.',
                'category' => 'Process',
                'tags' => ['light', 'portrait', 'process'],
                'excerpt' => 'Underexposing on purpose is not a style. It is what happens when you decide the shadow is the subject.',
                'body' => <<<'MD'
                    "Available light" is a phrase photographers use to mean *I did not bring any*. It is nearly always said with a small amount of pride, and it describes a constraint rather than an intention. Nobody says "available dark", and they should, because in most of the rooms I work in the shadow is the larger and more interesting half of the picture.

                    ## A room has a shape you cannot see

                    Stand in an unlit room at four in the afternoon in November and your eye adapts within about a minute. Everything becomes legible. A camera will not do that, and for a long time I treated the difference as a problem to be solved — open up, slow down, push the film, get the room back to what my eye was telling me.

                    That was the wrong instinct. What the eye does is flatten the room out, and the flattened version is not more true, it is just more convenient. A sensor or a negative given two stops less than it wants records what the light actually reached, which is a much smaller and stranger set of surfaces than you think you are looking at.

                    ## What underexposure does to a face

                    It removes information, and the removal is not neutral. A face lit from one side at two stops under keeps the forehead, the bridge of the nose and one cheek, and gives up everything else. What is left is a shape, and a shape reads as a person more strongly than a well-described face does, because the viewer finishes it.

                    That is the entire argument, and it is also the risk: a portrait where the viewer does the work can flatter somebody who does not deserve it, or make somebody look severe who is not. It is not a neutral technique dressed up as one.

                    ## The practical part

                    Three things, learned slowly:

                    - **Expose for the brightest thing you want to keep**, not for the face. If the window is in the frame and you want the window, that is the reading. The face will land where it lands.
                    - **Nothing recovers from clipped highlights.** A shadow at four stops under still has something in it. A blown highlight is paper.
                    - **Check on the back of the camera in the dark, not in the light.** A screen judged in a bright room lies by about a stop, always in the same direction, and you will go home with a set that is thinner than you thought.

                    ## Printing it

                    The print is where this either works or collapses. A file that looks decisive on a screen — a lit edge, a great deal of black — frequently prints as mud, because a screen emits and paper reflects, and the deep end of the scale on paper is a much shorter journey than the deep end on a monitor.

                    I print everything before I approve it, for exactly this reason. A picture that only works on a screen is not finished, and a room full of people looking at a phone is not a test of anything.
                    MD,
            ],
            [
                'slug' => 'a-negative-you-cannot-rescue',
                'title' => 'A negative you cannot rescue',
                'subtitle' => 'Three years in a darkroom before I owned a camera, and what it did to how I shoot.',
                'category' => 'Printing',
                'tags' => ['printing', 'darkroom', 'process'],
                'excerpt' => 'Learning to print first is the wrong way round and has been useful ever since.',
                'body' => <<<'MD'
                    I trained as a printer before I trained as a photographer. This is the wrong way round, everybody agrees it is the wrong way round, and it has been the single most useful accident of my working life.

                    ## What a printer learns

                    A printer spends three years looking at other people's negatives. Not their photographs — their negatives, which is a different and much less flattering document. You learn very quickly that the gap between a good photographer and a bad one is not composition. It is whether the negative gives you anything to work with.

                    A thin negative is a thin negative. You can dodge and burn and grade and split-filter and spend two hours on a sheet of paper, and at the end of it you have a print that announces exactly how hard you worked. Nobody wants that print. Meanwhile a well-exposed negative of a mediocre scene makes a clean, dull print in four minutes, and you go home.

                    ## The habit it builds

                    After three years of rescuing other people's mistakes you develop a physical reluctance to make your own. It is not discipline and it is not care. It is closer to superstition: I meter more than I need to, because I have stood in a dark room at eleven at night trying to find a shoulder that was never recorded.

                    > It is hard to overexpose a negative when you have spent three years trying to rescue somebody else's.

                    ## Where it goes wrong

                    It makes you conservative, and conservative is not free. There are pictures I did not take because the light was not where I wanted it and I knew what the negative would look like. Some of those were probably the better picture. A photographer who never learned to print takes the frame and finds out later, and about one time in twenty they are right and I was wrong.

                    I have not found a way to keep the caution and lose the cost. The best I manage is to notice when I am being careful about the wrong thing — when I am protecting the negative rather than making the photograph — and to take it anyway.

                    ## Why it still matters digitally

                    Nothing above stops applying because the capture is a file. The vocabulary changes and the physics does not: there is a point past which nothing was recorded, and no amount of work at the other end invents it. The difference is that a file lets you *find out immediately*, which sounds like an improvement and mostly means people check less carefully, because checking feels unnecessary right up until it is not.
                    MD,
            ],
            [
                'slug' => 'one-camera',
                'title' => 'One camera',
                'subtitle' => 'Not a discipline. Just what is left after the others stopped being used.',
                'category' => 'Equipment',
                'tags' => ['equipment', 'process'],
                'excerpt' => 'Every equipment article is secretly an argument for buying something. This one is not.',
                'body' => <<<'MD'
                    I work with one camera and one lens on almost every job, and people assume this is a discipline. It is not. It is what was left after I stopped carrying the things I was not using.

                    ## How it happened

                    For about four years I carried two bodies and four lenses to everything. At the end of a commission I would look at the take and find that between eighty and ninety per cent of the delivered frames came from the same body with the same lens on it. Not because that combination was better — because by the time I had decided which lens the moment wanted, the moment had views of its own.

                    The second body was insurance. The other three lenses were optimism.

                    ## What one lens actually changes

                    You stop choosing a focal length and start choosing a position. That is the whole of it, and it sounds like a slogan until you have done it for a month. The decision moves from your hands to your feet, and feet are slower, which means you arrive at a frame having walked around the problem rather than having zoomed at it.

                    It also makes you worse at some things, permanently. There are photographs I cannot take. A long lens compresses in a way that nothing else does and I have simply given that up; when a job genuinely needs it, I hire one and I am rusty for the first hour.

                    ## The part nobody writes

                    Equipment articles are almost always an argument for acquiring something, including the ones that claim to be about restraint — *I sold everything and kept one perfect camera* is still a shopping list, it just has one item on it.

                    So, plainly: the camera does not matter very much. Everything made in the last fifteen years is better than the person using it. What matters is that you know where the controls are without looking, that it does not fail in the cold, and that you are not thinking about it. Any body that clears those three is the right body, and the third one is the only one people argue about.

                    ## What I do spend on

                    Two things, without hesitation.

                    - **Cards and drives, in quantity.** A commission exists in three places by the time I leave, and one of them is not in the building. This has been worth it exactly once, which was enough.
                    - **Getting things repaired rather than replaced.** A shutter rebuilt at ten years is cheaper than a new body and produces the same photographs, and I already know where the controls are.
                    MD,
            ],
            [
                'slug' => 'the-brief-behind-the-brief',
                'title' => 'The brief behind the brief',
                'subtitle' => 'What a client asks for, and what they are actually worried about.',
                'category' => 'Commissions',
                'tags' => ['commissions', 'editorial'],
                'excerpt' => 'Almost every commission arrives with a stated brief and an unstated one. The second is the job.',
                'body' => <<<'MD'
                    Almost every commission arrives with two briefs. The first one is written down: eight portraits, a day and a half, delivered as high-resolution files by the end of the month. The second is not written down anywhere and is usually the actual job.

                    ## An example

                    A harbour trust once asked for "a photographic record of the site before the redevelopment". That is the stated brief and it is perfectly clear. What they were worried about — which took two conversations and a walk around the yard to surface — was that in thirty years nobody would believe it had been a working place rather than a derelict one.

                    That is a completely different commission. A record would have been a systematic sweep: every building, every elevation, evenly lit, nothing dramatic. What they needed was evidence of work, which meant hands, wear, things left mid-task, and the specific mess that only exists where something is actually being made.

                    I photographed roughly a third of the site and delivered forty frames. A record would have been three hundred and would have proved their fear right.

                    ## How to find it

                    Ask what the pictures are for, and then ask what happens to them. Not "where will they be used" — what *happens* to them. Who sees them, in what order, next to what else, and what do you want that person to think afterwards.

                    People find this question easy to answer and rarely get asked it. The answer is almost never the same as the stated brief, and the gap between the two is where a commission is either good or merely completed.

                    ## When the two briefs conflict

                    Sometimes they do, and this is worth saying out loud early rather than discovering it in the edit. If a magazine needs a frame that will hold a cover line across the top third, that is a real constraint and no amount of intention about the unstated brief overrides it.

                    The answer is not to quietly serve the brief you prefer. It is to say: here is the frame that does the job you asked for, and here are four that do the thing I think you actually want, and you should have both. Nobody has ever objected to being given both.

                    ## The one that got away

                    I have taken a job where I never found the second brief. The pictures were fine, the client was content, and the whole thing has the flat quality of work that answered a question nobody was asking. It is the only set I have made that I could not tell you anything about.
                    MD,
            ],
            [
                'slug' => 'three-rolls-in-november',
                'title' => 'Three rolls in November',
                'subtitle' => 'A short note about working when there is no light left.',
                'category' => 'Process',
                'tags' => ['light', 'place', 'process'],
                'excerpt' => 'Between the middle of November and the end of January there are about six usable hours a day. This is not a problem.',
                'body' => <<<'MD'
                    Between the middle of November and the end of January this far north there are about six usable hours, and two of those are the kind of grey that flattens everything to the same value. Most years somebody asks me how I work through it. The honest answer is that it is the best part of the year.

                    Six hours is a constraint that makes decisions for you. There is no version of the day where you wander about waiting for something; you go to the one place you had in mind, at the one time it might work, and if it does not work you go home. I shoot less and keep more between November and February than in any other season, by a considerable margin.

                    The light itself is also simply better. Low, sideways, and on the days it does arrive it arrives with an edge to it that summer never has. A summer noon in this country is a lighting setup nobody would choose.

                    What it costs is certainty. Three rolls is a good day in December and a slow morning in June, and if the weather closes in there is no recovering the day — you have lost it, and the job is a day longer. That has to be in the quote, and it has to be said before the job rather than after.
                    MD,
            ],
        ];
    }
}
