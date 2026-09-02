import zemberek.core.logging.Log;
import zemberek.core.turkish.PrimaryPos;
import zemberek.core.turkish.SecondaryPos;
import zemberek.core.turkish.Turkish;
import zemberek.morphology.TurkishMorphology;
import zemberek.morphology.analysis.SingleAnalysis;
import zemberek.morphology.analysis.WordAnalysis;
import zemberek.morphology.analysis.WordAnalysisSurfaceFormatter;
import zemberek.morphology.morphotactics.Morpheme;
import zemberek.normalization.TurkishSentenceNormalizer;
import zemberek.normalization.TurkishSpellChecker;
import zemberek.normalization.deasciifier.Deasciifier;
import zemberek.tokenization.Token;
import zemberek.tokenization.TurkishTokenizer;

import java.io.BufferedReader;
import java.io.BufferedWriter;
import java.io.InputStreamReader;
import java.io.OutputStreamWriter;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.Collections;
import java.util.HashMap;
import java.util.HashSet;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Set;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

public class ZemberekSuggest {
    private static final Locale TR = Turkish.LOCALE;
    private static final WordAnalysisSurfaceFormatter FORMATTER = new WordAnalysisSurfaceFormatter();
    private static final String[] QUESTION_PARTICLES = {
            "mıydı", "miydi", "muydu", "müydü",
            "mıydım", "miydim", "muydum", "müydüm",
            "mıydın", "miydin", "muydun", "müydün",
            "mıydık", "miydik", "muyduk", "müydük",
            "mıydınız", "miydiniz", "muydunuz", "müydünüz",
            "mısın", "misin", "musun", "müsün",
            "mıyız", "miyiz", "muyuz", "müyüz",
            "mıyım", "miyim", "muyum", "müyüm",
            "mısınız", "misiniz", "musunuz", "müsünüz",
            "mıdır", "midir", "mudur", "müdür",
            "mı", "mi", "mu", "mü"
    };
    // Conjunction / relative clitics only (not case endings like ye/ya).
    private static final String[] CLITICS = {"de", "da", "ki"};
    private static final Set<String> QUESTION_PARTICLE_SET = new HashSet<>(Arrays.asList(QUESTION_PARTICLES));

    /**
     * Common non-pronoun hosts that often glue to -mi/-mu in informal writing
     * (öylemi, değilmi, varmı). Deliberately excludes ordinary nouns so odamı stays whole.
     */
    private static final Set<String> QUESTION_PEEL_HOST_ALLOWLIST = new HashSet<>(Arrays.asList(
            "öyle", "böyle", "değil", "var", "yok", "doğru", "yanlış", "tamam",
            "evet", "hayır", "belki", "acaba", "tabii", "elbette", "peki", "hâlâ", "hala",
            "şimdi", "artık", "hiç", "gerek", "lazım", "mümkün",
            "imkansız", "imkânsız", "emin", "hazır", "boş", "dolu", "iyi", "kötü",
            "güzel", "fena", "kolay", "zor", "gerekli", "yeter", "yeterli"
    ));

    /**
     * Clause / discourse connectors that usually take a preceding comma when they
     * join two clauses mid-sentence (edebilir, aynı zamanda ...).
     * Longer phrases are listed first and matched greedily.
     */
    private static final String[][] CLAUSE_COMMA_CONNECTORS = {
            {"aynı", "zamanda"},
            {"bununla", "birlikte"},
            {"buna", "rağmen"},
            {"buna", "karşın"},
            {"buna", "ek", "olarak"},
            {"öte", "yandan"},
            {"diğer", "yandan"},
            {"bir", "yandan"},
            {"ne", "var", "ki"},
            {"oysa", "ki"},
            {"her", "ne", "kadar"},
            {"sonuç", "olarak"},
            {"bu", "nedenle"},
            {"bu", "yüzden"},
            {"buna", "göre"},
            {"buna", "bağlı", "olarak"},
            {"kısacası"},
            {"özetle"},
            {"ayrıca"},
            {"ancak"},
            {"fakat"},
            {"lakin"},
            {"oysa"},
            {"çünkü"},
            {"dolayısıyla"},
            {"nitekim"},
            {"üstelik"},
            {"hatta"},
            {"yani"},
            {"örneğin"},
            {"mesela"},
            {"özellikle"},
            {"bilhassa"},
            {"keza"},
            {"yine", "de"},
            {"böylece"},
            {"bundan", "dolayı"},
            {"bundan", "ötürü"}
    };

    /** elon.io: Evet/Hayır/Peki/Yani and similar openers take a following comma. */
    private static final Set<String> DISCOURSE_OPENER_COMMA = new HashSet<>(Arrays.asList(
            "evet", "hayır", "hayir", "peki", "yani", "tabii", "tabi", "elbette",
            "haydi", "hadi", "maalesef", "malesef", "lütfen", "lutfen", "tamam",
            "pekâlâ", "pekala", "aslında", "aslinda", "valla", "vallahi"
    ));

    /** Reporting verbs/continuers after a closing quotation mark. */
    private static final Set<String> QUOTE_REPORTING_CONTINUERS = new HashSet<>(Arrays.asList(
            "dedi", "demiş", "demişti", "diyor", "diyordu", "diyecek", "diye", "diyerek",
            "diyince", "deyip", "sordu", "sormuş", "sormuştu", "cevapladı", "yanıtladı",
            "ekledi", "belirtti", "söyledi", "soyledi", "yazdı", "yazmış"
    ));

    /** English thousands with optional decimal: 1,500 / 1,500.75 */
    private static final Pattern ENG_THOUSANDS_NUMBER = Pattern.compile(
            "(?<![\\d.,])(\\d{1,3}(?:,\\d{3})+)(?:\\.(\\d+))?(?![\\d.,])"
    );
    /** English decimal + Turkish apostrophe suffix: 3.14'tür */
    private static final Pattern ENG_DECIMAL_BEFORE_SUFFIX = Pattern.compile(
            "(?<![\\d.,])(\\d+)\\.(\\d+)'([\\p{L}]+)"
    );
    /** English decimal before a unit word: 3.14 lira */
    private static final Pattern ENG_DECIMAL_BEFORE_UNIT = Pattern.compile(
            "(?<![\\d.,])(\\d+)\\.(\\d+)(?=\\s+(?:lira|tl|try|usd|eur|euro|dolar|cent|kuruş|kurus|metre|km|kg|gr|cm|mm|lt|ml|yüzde|yuzde|oran|puan|derece)\\b)",
            Pattern.CASE_INSENSITIVE | Pattern.UNICODE_CASE
    );
    private static final Pattern ENG_DECIMAL_SAFE = Pattern.compile(
            "(?<![\\d.,])(\\d+)\\.(\\d+)(?![\\d.,])"
    );

    // Preferred leet substitutions first; alternates used only if preferred fails.
    private static final Map<Character, char[]> LEET_MAP = buildLeetMap();
    private static final Map<Character, Character> LEET_PREFERRED = buildLeetPreferred();
    private static final Set<Character> FRONT_VOWELS = new HashSet<>(Arrays.asList('e', 'i', 'ö', 'ü'));
    private static final Set<Character> BACK_VOWELS = new HashSet<>(Arrays.asList('a', 'ı', 'o', 'u'));
    private static final int LEET_CANDIDATE_CAP = 96;

    private static Map<Character, char[]> buildLeetMap() {
        Map<Character, char[]> map = new HashMap<>();
        map.put('0', new char[]{'o', 'ö'});
        map.put('1', new char[]{'i', 'ı', 'l'});
        map.put('3', new char[]{'e'});
        map.put('4', new char[]{'a'});
        map.put('5', new char[]{'s', 'ş'});
        map.put('7', new char[]{'t'});
        map.put('8', new char[]{'b'});
        map.put('9', new char[]{'g', 'ğ'});
        map.put('@', new char[]{'a'});
        map.put('$', new char[]{'s', 'ş'});
        map.put('!', new char[]{'i', 'ı'});
        map.put('|', new char[]{'l', 'i'});
        return map;
    }

    private static Map<Character, Character> buildLeetPreferred() {
        Map<Character, Character> map = new HashMap<>();
        map.put('0', 'o');
        map.put('1', 'i');
        map.put('3', 'e');
        map.put('4', 'a');
        map.put('5', 's');
        map.put('7', 't');
        map.put('8', 'b');
        map.put('9', 'g');
        map.put('@', 'a');
        map.put('$', 's');
        map.put('!', 'i');
        map.put('|', 'l');
        return map;
    }

    public static void main(String[] args) throws Exception {
        Log.setError();
        String input = readAll();
        if (input == null || input.trim().isEmpty()) {
            return;
        }

        TurkishMorphology morphology = TurkishMorphology.createWithDefaults();
        TurkishSpellChecker spellChecker = new TurkishSpellChecker(morphology);
        TurkishSentenceNormalizer sentenceNormalizer = tryCreateSentenceNormalizer(morphology);

        // Pass 1: token-local correction including leetspeak / alphanumerical tokens.
        String pass1 = correctTokens(input, morphology, spellChecker);

        // Pass 2: optional full-sentence normalizer + LM when data is present.
        String pass2 = pass1;
        if (sentenceNormalizer != null) {
            try {
                pass2 = normalizeWithSentenceNormalizer(pass1, sentenceNormalizer);
            } catch (Exception ignored) {
                pass2 = pass1;
            }
        }

        // Pass 3: residual peel/proper on still-noisy tokens + harmony for free question particles.
        String pass3 = correctTokens(pass2, morphology, spellChecker);
        pass3 = applyQuestionParticleHarmony(pass3, morphology, spellChecker);

        String polished = polishText(normalizeSpaces(pass3), morphology);
        writeAll(normalizeSpaces(polished));
    }

    private static String correctTokens(
            String input,
            TurkishMorphology morphology,
            TurkishSpellChecker spellChecker
    ) {
        List<Token> tokens = TurkishTokenizer.DEFAULT.tokenize(input);
        StringBuilder out = new StringBuilder();
        for (Token token : tokens) {
            String tokenText = token.getText();
            String replacement = tokenText;
            Token.Type type = token.getType();

            if (type == Token.Type.Word
                    || type == Token.Type.WordWithSymbol
                    || type == Token.Type.WordAlphanumerical
                    || type == Token.Type.UnknownWord) {
                if (!isProtectedToken(type, tokenText)) {
                    replacement = correctWord(tokenText, morphology, spellChecker);
                }
            }

            if (shouldPrependSpace(out, replacement, token)) {
                out.append(' ');
            }
            out.append(replacement);
        }
        return normalizeSpaces(out.toString());
    }

    private static boolean isProtectedToken(Token.Type type, String tokenText) {
        if (type == Token.Type.URL
                || type == Token.Type.Email
                || type == Token.Type.HashTag
                || type == Token.Type.Mention
                || type == Token.Type.Number
                || type == Token.Type.Time
                || type == Token.Type.Date
                || type == Token.Type.PercentNumeral) {
            return true;
        }
        if (tokenText == null || tokenText.isEmpty()) {
            return true;
        }
        return isPureNumber(tokenText);
    }

    private static TurkishSentenceNormalizer tryCreateSentenceNormalizer(TurkishMorphology morphology) {
        try {
            Path dataRoot = resolveDataRoot();
            if (dataRoot == null) {
                return null;
            }
            Path lookupRoot = dataRoot.resolve("normalization");
            Path lmPath = dataRoot.resolve("lm").resolve("lm.2gram.slm");
            if (!Files.isDirectory(lookupRoot) || !Files.isRegularFile(lmPath)) {
                return null;
            }
            if (!Files.isRegularFile(lookupRoot.resolve("lookup-from-graph"))
                    || !Files.isRegularFile(lookupRoot.resolve("ascii-map"))
                    || !Files.isRegularFile(lookupRoot.resolve("split"))) {
                return null;
            }
            return new TurkishSentenceNormalizer(morphology, lookupRoot, lmPath);
        } catch (Exception e) {
            System.err.println("Sentence normalizer unavailable: " + e.getMessage());
            return null;
        }
    }

    private static Path resolveDataRoot() {
        String env = System.getenv("ZEMBEREK_DATA_ROOT");
        if (env != null && !env.trim().isEmpty()) {
            Path p = Paths.get(env.trim());
            if (Files.isDirectory(p)) {
                return p;
            }
        }
        Path local = Paths.get("zemberek-data");
        if (Files.isDirectory(local)) {
            return local.toAbsolutePath().normalize();
        }
        Path besideJar = Paths.get(System.getProperty("user.dir", ".")).resolve("zemberek-data");
        if (Files.isDirectory(besideJar)) {
            return besideJar.toAbsolutePath().normalize();
        }
        return null;
    }

    private static String normalizeWithSentenceNormalizer(
            String inputText,
            TurkishSentenceNormalizer normalizer
    ) {
        if (inputText == null || inputText.trim().isEmpty()) {
            return inputText;
        }
        List<String> sentences = splitSentences(inputText);
        StringBuilder out = new StringBuilder();
        for (String sentence : sentences) {
            String trimmed = sentence.trim();
            if (trimmed.isEmpty()) {
                continue;
            }
            char terminal = 0;
            String body = trimmed;
            char last = trimmed.charAt(trimmed.length() - 1);
            if (last == '.' || last == '!' || last == '?' || last == '…') {
                terminal = last;
                body = trimmed.substring(0, trimmed.length() - 1).trim();
            }
            String normalized = body.isEmpty() ? body : normalizer.normalize(body);
            if (normalized == null) {
                normalized = body;
            }
            if (out.length() > 0) {
                out.append(' ');
            }
            out.append(normalized.trim());
            if (terminal != 0) {
                out.append(terminal);
            }
        }
        return normalizeSpaces(out.toString());
    }

    /**
     * Standalone question particles: rewrite back-vowel form to front (or vice versa)
     * using the previous content word's last vowel. Example: günde musunuz -> günde misiniz.
     */
    private static String applyQuestionParticleHarmony(
            String inputText,
            TurkishMorphology morphology,
            TurkishSpellChecker spellChecker
    ) {
        List<Token> tokens = TurkishTokenizer.DEFAULT.tokenize(inputText);
        if (tokens.isEmpty()) {
            return inputText;
        }
        List<String> words = new ArrayList<>();
        List<Token.Type> types = new ArrayList<>();
        for (Token token : tokens) {
            words.add(token.getText());
            types.add(token.getType());
        }

        String lastContentLower = null;
        StringBuilder out = new StringBuilder();
        for (int i = 0; i < words.size(); i++) {
            String word = words.get(i);
            Token.Type type = types.get(i);
            String next = word;

            boolean isWordish = type == Token.Type.Word
                    || type == Token.Type.WordWithSymbol
                    || type == Token.Type.WordAlphanumerical;

            if (isWordish) {
                String lower = word.toLowerCase(TR);
                if (QUESTION_PARTICLE_SET.contains(lower) && lastContentLower != null) {
                    String harmonized = harmonizeQuestionParticle(lower, lastContentLower, morphology, spellChecker);
                    if (harmonized != null) {
                        next = preserveCase(word, harmonized);
                    }
                }
                if (!QUESTION_PARTICLE_SET.contains(word.toLowerCase(TR))) {
                    lastContentLower = word.toLowerCase(TR);
                }
            }

            if (shouldPrependSpace(out, next, type)) {
                out.append(' ');
            }
            out.append(next);
        }
        return normalizeSpaces(out.toString());
    }

    private static String harmonizeQuestionParticle(
            String particleLower,
            String previousLower,
            TurkishMorphology morphology,
            TurkishSpellChecker spellChecker
    ) {
        Character prevVowel = lastVowel(previousLower);
        if (prevVowel == null) {
            return null;
        }
        // Four-way Turkish vowel harmony for question particles:
        // a/ı -> ı-forms, e/i -> i-forms, o/u -> u-forms, ö/ü -> ü-forms.
        char harmonyVowel = harmonyClassVowel(prevVowel);
        String candidate = retargetQuestionParticle(particleLower, harmonyVowel);
        if (candidate == null || candidate.equals(particleLower)) {
            return null;
        }
        if (isAcceptableWord(morphology, spellChecker, candidate)
                || QUESTION_PARTICLE_SET.contains(candidate)) {
            return candidate;
        }
        return null;
    }

    private static char harmonyClassVowel(char vowel) {
        switch (vowel) {
            case 'a':
            case 'ı':
                return 'ı';
            case 'e':
            case 'i':
                return 'i';
            case 'o':
            case 'u':
                return 'u';
            case 'ö':
            case 'ü':
                return 'ü';
            default:
                return vowel;
        }
    }

    private static String retargetQuestionParticle(String particle, char harmonyVowel) {
        if (particle == null || particle.isEmpty() || particle.charAt(0) != 'm') {
            return null;
        }
        // Template keys use ı as the variable vowel placeholder family.
        String[] family = questionParticleFamily(particle);
        if (family == null) {
            return null;
        }
        switch (harmonyVowel) {
            case 'ı':
                return family[0];
            case 'i':
                return family[1];
            case 'u':
                return family[2];
            case 'ü':
                return family[3];
            default:
                return null;
        }
    }

    /**
     * Returns the four harmony variants [ı, i, u, ü] for a known question particle shape.
     */
    private static String[] questionParticleFamily(String particle) {
        String p = particle.toLowerCase(TR);
        // Normalize to bare shape using ı-form keys.
        String key = p
                .replace('i', 'ı').replace('u', 'ı').replace('ü', 'ı')
                .replace('e', 'a').replace('ö', 'a').replace('o', 'a');
        // After crude replace, u/ü/i already mapped; also map any leftover.
        switch (p) {
            case "mı": case "mi": case "mu": case "mü":
                return new String[]{"mı", "mi", "mu", "mü"};
            case "mıydı": case "miydi": case "muydu": case "müydü":
                return new String[]{"mıydı", "miydi", "muydu", "müydü"};
            case "mıydım": case "miydim": case "muydum": case "müydüm":
                return new String[]{"mıydım", "miydim", "muydum", "müydüm"};
            case "mıydın": case "miydin": case "muydun": case "müydün":
                return new String[]{"mıydın", "miydin", "muydun", "müydün"};
            case "mıydık": case "miydik": case "muyduk": case "müydük":
                return new String[]{"mıydık", "miydik", "muyduk", "müydük"};
            case "mıydınız": case "miydiniz": case "muydunuz": case "müydünüz":
                return new String[]{"mıydınız", "miydiniz", "muydunuz", "müydünüz"};
            case "mısın": case "misin": case "musun": case "müsün":
                return new String[]{"mısın", "misin", "musun", "müsün"};
            case "mıyız": case "miyiz": case "muyuz": case "müyüz":
                return new String[]{"mıyız", "miyiz", "muyuz", "müyüz"};
            case "mıyım": case "miyim": case "muyum": case "müyüm":
                return new String[]{"mıyım", "miyim", "muyum", "müyüm"};
            case "mısınız": case "misiniz": case "musunuz": case "müsünüz":
                return new String[]{"mısınız", "misiniz", "musunuz", "müsünüz"};
            case "mıdır": case "midir": case "mudur": case "müdür":
                return new String[]{"mıdır", "midir", "mudur", "müdür"};
            default:
                return null;
        }
    }

    private static Character lastVowel(String word) {
        if (word == null || word.isEmpty()) {
            return null;
        }
        for (int i = word.length() - 1; i >= 0; i--) {
            char c = word.substring(i, i + 1).toLowerCase(TR).charAt(0);
            if (FRONT_VOWELS.contains(c) || BACK_VOWELS.contains(c)) {
                return c;
            }
        }
        return null;
    }

    private static String correctWord(
            String text,
            TurkishMorphology morphology,
            TurkishSpellChecker spellChecker
    ) {
        if (text == null || text.isEmpty()) {
            return text;
        }

        // Leetspeak / digit-letter mixes first (3vl3r3 -> evlere). Also helps WordAlphanumerical.
        String leet = tryDecodeLeetspeak(text, morphology, spellChecker);
        if (leet != null) {
            return leet;
        }

        // Apostrophe noise on otherwise normal words (Gun'du -> günde).
        String apostropheNoise = tryFixApostropheNoise(text, morphology, spellChecker);
        if (apostropheNoise != null) {
            return apostropheNoise;
        }

        // Spell-OK words can still hide glued question particles with a false morphology
        // (Bizimi = biz+P1sg+Acc, intended "bizi mi"). Try a narrow question peel first.
        // Do not peel solid possessives (odamı) or ordinary words (kitapta, sandınız).
        if (spellChecker.check(text)) {
            String gluedQ = tryPeelGluedQuestionParticle(text, morphology, spellChecker);
            if (gluedQ != null) {
                return gluedQ;
            }
            if (Character.isUpperCase(text.charAt(0)) || hasApostrophe(text)) {
                String proper = tryFormatProperNoun(text, morphology, false);
                if (proper != null) {
                    return proper;
                }
            }
            return text;
        }

        // Whole-word deasciify first when the full token becomes valid (boyle -> böyle).
        String deascii = tryDeasciify(text, morphology, spellChecker);
        if (deascii != null) {
            return deascii;
        }

        // Multi-layer peel before generic spell suggestions so oldumu -> oldu mu,
        // Bursayadamı -> Bursa'ya da mı. Residual heads are NOT spell-fixed aggressively,
        // so Yaaptıklarımı does not become "Yaptıkları mı".
        String peeled = tryPeelConnectedParticles(text, morphology, spellChecker);
        if (peeled != null) {
            return peeled;
        }

        String deForm = safeDeasciify(text);
        if (deForm != null && !deForm.equalsIgnoreCase(text)) {
            String peeledDe = tryPeelConnectedParticles(deForm, morphology, spellChecker);
            if (peeledDe != null) {
                return peeledDe;
            }
        }

        String proper = tryFormatProperNoun(text, morphology, false);
        if (proper != null) {
            return proper;
        }

        List<String> suggestions = spellChecker.suggestForWord(text);
        if (suggestions != null && !suggestions.isEmpty()) {
            String best = pickBestSuggestion(text, suggestions, morphology, spellChecker);
            if (best != null) {
                return best;
            }
        }

        return text;
    }


    private static boolean isPureNumber(String text) {
        if (text == null || text.isEmpty()) {
            return false;
        }
        for (int i = 0; i < text.length(); ) {
            int cp = text.codePointAt(i);
            i += Character.charCount(cp);
            if (!Character.isDigit(cp) && cp != '.' && cp != ',' && cp != '+' && cp != '-') {
                return false;
            }
        }
        return true;
    }

    private static boolean looksLikeLeet(String text) {
        if (text == null || text.length() < 2) {
            return false;
        }
        boolean hasLetter = false;
        boolean hasLeet = false;
        int leetCount = 0;
        for (int i = 0; i < text.length(); ) {
            int cp = text.codePointAt(i);
            i += Character.charCount(cp);
            if (Character.isLetter(cp)) {
                hasLetter = true;
            } else if (LEET_MAP.containsKey((char) cp) || Character.isDigit(cp)) {
                hasLeet = true;
                leetCount++;
            }
        }
        if (hasLetter && hasLeet) {
            return true;
        }
        // Short full-leet tokens made only of digits/symbols.
        if (!hasLetter && hasLeet && text.length() <= 4 && leetCount == text.length()) {
            return true;
        }
        return false;
    }

    private static String tryDecodeLeetspeak(
            String text,
            TurkishMorphology morphology,
            TurkishSpellChecker spellChecker
    ) {
        if (!looksLikeLeet(text)) {
            return null;
        }
        if (isPureNumber(text)) {
            return null;
        }

        String preferred = decodeLeetPreferred(text);
        List<String> ordered = new ArrayList<>();
        if (preferred != null) {
            ordered.add(preferred);
        }
        if (preferred == null || !isStrongLeetAccept(preferred, morphology, spellChecker)) {
            for (String cand : expandLeetCandidates(text)) {
                if (!ordered.contains(cand)) {
                    ordered.add(cand);
                }
            }
        }

        String best = null;
        int bestScore = Integer.MIN_VALUE;
        for (String cand : ordered) {
            String resolved = resolveLeetCandidate(cand, morphology, spellChecker);
            if (resolved == null) {
                continue;
            }
            int score = scoreLeetCandidate(text, cand, resolved, morphology, spellChecker);
            if (score > bestScore) {
                bestScore = score;
                best = resolved;
            }
        }
        if (best == null || bestScore < 60) {
            return null;
        }
        return best;
    }

    private static boolean isStrongLeetAccept(
            String candidate,
            TurkishMorphology morphology,
            TurkishSpellChecker spellChecker
    ) {
        String resolved = resolveLeetCandidate(candidate, morphology, spellChecker);
        return resolved != null && isAcceptableWord(morphology, spellChecker, resolved);
    }

    private static String decodeLeetPreferred(String text) {
        StringBuilder sb = new StringBuilder(text.length());
        for (int i = 0; i < text.length(); ) {
            int cp = text.codePointAt(i);
            i += Character.charCount(cp);
            if (cp > 0xFFFF) {
                sb.appendCodePoint(cp);
                continue;
            }
            char ch = (char) cp;
            Character pref = LEET_PREFERRED.get(ch);
            if (pref != null) {
                sb.append(pref.charValue());
            } else {
                sb.append(ch);
            }
        }
        return sb.toString();
    }

    private static List<String> expandLeetCandidates(String text) {
        List<String> results = new ArrayList<>();
        expandLeetRec(text, 0, new StringBuilder(), results);
        return results;
    }

    private static void expandLeetRec(String text, int index, StringBuilder current, List<String> out) {
        if (out.size() >= LEET_CANDIDATE_CAP) {
            return;
        }
        if (index >= text.length()) {
            out.add(current.toString());
            return;
        }
        int cp = text.codePointAt(index);
        int next = index + Character.charCount(cp);
        if (cp > 0xFFFF) {
            current.appendCodePoint(cp);
            expandLeetRec(text, next, current, out);
            current.setLength(current.length() - Character.charCount(cp));
            return;
        }
        char ch = (char) cp;
        char[] alts = LEET_MAP.get(ch);
        if (alts == null) {
            current.append(ch);
            expandLeetRec(text, next, current, out);
            current.setLength(current.length() - 1);
            return;
        }
        Character pref = LEET_PREFERRED.get(ch);
        if (pref != null) {
            current.append(pref.charValue());
            expandLeetRec(text, next, current, out);
            current.setLength(current.length() - 1);
        }
        for (char alt : alts) {
            if (pref != null && alt == pref.charValue()) {
                continue;
            }
            if (out.size() >= LEET_CANDIDATE_CAP) {
                return;
            }
            current.append(alt);
            expandLeetRec(text, next, current, out);
            current.setLength(current.length() - 1);
        }
    }

    private static String resolveLeetCandidate(
            String candidate,
            TurkishMorphology morphology,
            TurkishSpellChecker spellChecker
    ) {
        if (candidate == null || candidate.isEmpty()) {
            return null;
        }
        String stripped = stripApostrophes(candidate);
        LinkedHashSet<String> forms = new LinkedHashSet<>();
        forms.add(candidate);
        forms.add(stripped);
        forms.add(candidate.toLowerCase(TR));
        forms.add(stripped.toLowerCase(TR));

        String de = safeDeasciify(stripped);
        if (de != null) {
            forms.add(de);
            forms.add(de.toLowerCase(TR));
        }
        String deFull = safeDeasciify(candidate);
        if (deFull != null) {
            forms.add(deFull);
            forms.add(deFull.toLowerCase(TR));
        }

        String best = null;
        int bestScore = Integer.MIN_VALUE;
        for (String form : forms) {
            if (!isSafeTurkishToken(form)) {
                continue;
            }
            if (!isAcceptableWord(morphology, spellChecker, form)) {
                continue;
            }
            int score = 80;
            if (spellChecker.check(form)) {
                score += 10;
            }
            if (hasRegularAnalysis(morphology, form)) {
                score += 8;
            }
            if (form.equals(form.toLowerCase(TR))) {
                score += 2;
            }
            if (score > bestScore) {
                bestScore = score;
                best = form;
            }
        }
        if (best != null) {
            return finalizeLeetSurface(candidate, best, morphology);
        }

        String base = stripped.toLowerCase(TR);
        List<String> suggestions = spellChecker.suggestForWord(base);
        if (suggestions != null) {
            String picked = pickBestSuggestion(base, suggestions, morphology, spellChecker);
            if (picked != null) {
                return finalizeLeetSurface(candidate, picked, morphology);
            }
        }
        if (de != null) {
            suggestions = spellChecker.suggestForWord(de.toLowerCase(TR));
            if (suggestions != null) {
                String picked = pickBestSuggestion(de, suggestions, morphology, spellChecker);
                if (picked != null) {
                    return finalizeLeetSurface(candidate, picked, morphology);
                }
            }
        }
        return null;
    }

    private static String finalizeLeetSurface(
            String originalLeet,
            String resolved,
            TurkishMorphology morphology
    ) {
        if (resolved == null) {
            return null;
        }
        if (hasApostrophe(resolved) && isLikelyProperSurface(resolved, morphology)) {
            return resolved;
        }
        return resolved.toLowerCase(TR);
    }

    private static int scoreLeetCandidate(
            String original,
            String rawDecoded,
            String resolved,
            TurkishMorphology morphology,
            TurkishSpellChecker spellChecker
    ) {
        String origLetters = stripDiacritics(stripApostrophes(decodeLeetPreferred(original)).toLowerCase(TR));
        String resLetters = stripDiacritics(stripApostrophes(resolved).toLowerCase(TR));
        int distance = levenshtein(origLetters, resLetters);

        int score = 100;
        score -= distance * 10;
        score -= Math.abs(origLetters.length() - resLetters.length()) * 3;
        if (spellChecker.check(resolved)) {
            score += 12;
        }
        if (hasRegularAnalysis(morphology, resolved)) {
            score += 10;
        } else if (isLikelyProperSurface(resolved, morphology)) {
            score -= 8;
        }
        String pref = decodeLeetPreferred(original).toLowerCase(TR);
        score -= levenshtein(stripDiacritics(stripApostrophes(pref)), resLetters) * 2;
        if (rawDecoded != null && rawDecoded.equalsIgnoreCase(resolved)) {
            score += 5;
        }
        return score;
    }

    /**
     * Fix informal apostrophe noise such as Gun'du where the apostrophe is not a true
     * proper-noun mark. Prefers regular dictionary words over false proper suggestions.
     */
    private static String tryFixApostropheNoise(
            String text,
            TurkishMorphology morphology,
            TurkishSpellChecker spellChecker
    ) {
        if (!hasApostrophe(text) || text.length() < 3) {
            return null;
        }
        if (spellChecker.check(text) && hasApostrophe(text) && Character.isUpperCase(text.charAt(0))) {
            // Still try if the only analysis is proper-ish noise; but keep true proper forms.
            if (hasRegularAnalysis(morphology, text)) {
                return null;
            }
        }

        String stripped = stripApostrophes(text);
        if (stripped.length() < 2) {
            return null;
        }

        LinkedHashSet<String> candidates = new LinkedHashSet<>();
        candidates.add(stripped);
        candidates.add(stripped.toLowerCase(TR));
        String de = safeDeasciify(stripped);
        if (de != null) {
            candidates.add(de);
            candidates.add(de.toLowerCase(TR));
        }
        for (String base : new ArrayList<>(candidates)) {
            candidates.addAll(vowelEndVariants(base));
        }

        String best = null;
        int bestScore = Integer.MIN_VALUE;
        String strippedLower = stripped.toLowerCase(TR);
        String textLower = text.toLowerCase(TR);
        for (String cand : candidates) {
            if (!isSafeTurkishToken(cand)) {
                continue;
            }
            if (!isAcceptableWord(morphology, spellChecker, cand)) {
                continue;
            }
            if (!hasRegularAnalysis(morphology, cand) && isLikelyProperSurface(cand, morphology)) {
                continue;
            }
            int distance = levenshtein(stripDiacritics(strippedLower), stripDiacritics(cand.toLowerCase(TR)));
            if (distance > 2) {
                continue;
            }
            int score = 90 - distance * 12;
            if (spellChecker.check(cand)) {
                score += 10;
            }
            if (hasRegularAnalysis(morphology, cand)) {
                score += 12;
            }
            String cl = cand.toLowerCase(TR);
            // Noisy apostrophe before du/dü is often a mistyped locative (Gun'du -> günde),
            // not past tense. Prefer -de/-da strongly when both are morphologically valid.
            if (strippedLower.endsWith("du") || strippedLower.endsWith("dü")
                    || textLower.endsWith("'du") || textLower.endsWith("'dü")
                    || textLower.endsWith("’du") || textLower.endsWith("’dü")
                    || textLower.contains("'d") || textLower.contains("’d")) {
                if (cl.endsWith("de") || cl.endsWith("da") || cl.endsWith("te") || cl.endsWith("ta")) {
                    score += 20;
                } else if (cl.endsWith("dü") || cl.endsWith("du") || cl.endsWith("di") || cl.endsWith("dı")
                        || cl.endsWith("tu") || cl.endsWith("tü") || cl.endsWith("ti") || cl.endsWith("tı")) {
                    score -= 6;
                }
            }
            if (score > bestScore) {
                bestScore = score;
                best = cand;
            }
        }

        if (best == null) {
            List<String> suggestions = spellChecker.suggestForWord(strippedLower);
            if (suggestions != null) {
                for (String sug : suggestions) {
                    if (!isAcceptableWord(morphology, spellChecker, sug)) {
                        continue;
                    }
                    if (!hasRegularAnalysis(morphology, sug)) {
                        continue;
                    }
                    int distance = levenshtein(stripDiacritics(strippedLower), stripDiacritics(sug.toLowerCase(TR)));
                    if (distance > 2) {
                        continue;
                    }
                    int score = 80 - distance * 12;
                    if (score > bestScore) {
                        bestScore = score;
                        best = sug;
                    }
                }
            }
        }

        if (best == null || bestScore < 70) {
            return null;
        }
        if (hasApostrophe(best)) {
            return best;
        }
        return best.toLowerCase(TR);
    }

    private static List<String> vowelEndVariants(String word) {
        if (word == null || word.length() < 3) {
            return Collections.emptyList();
        }
        List<String> out = new ArrayList<>();
        String lower = word.toLowerCase(TR);
        int lastVowelIdx = -1;
        for (int i = lower.length() - 1; i >= 0; i--) {
            char c = lower.charAt(i);
            if ("aeıioöuü".indexOf(c) >= 0) {
                lastVowelIdx = i;
                break;
            }
        }
        if (lastVowelIdx < 0) {
            return out;
        }
        char[] alts = new char[]{'a', 'e', 'ı', 'i', 'o', 'ö', 'u', 'ü'};
        for (char alt : alts) {
            if (alt == lower.charAt(lastVowelIdx)) {
                continue;
            }
            String v = lower.substring(0, lastVowelIdx) + alt + lower.substring(lastVowelIdx + 1);
            out.add(v);
        }
        List<String> extra = new ArrayList<>();
        for (String v : out) {
            String d = safeDeasciify(v);
            if (d != null && !d.equals(v)) {
                extra.add(d.toLowerCase(TR));
            }
        }
        out.addAll(extra);
        return out;
    }

    /**
     * Peel glued question particles from dictionary-true tokens when the residual is a
     * safe host (pronoun or common tag-question word). Example: Bizimi -> Bizi mi.
     * Leaves genuine possessives like odamı / kitabımı intact.
     */
    private static String tryPeelGluedQuestionParticle(
            String text,
            TurkishMorphology morphology,
            TurkishSpellChecker spellChecker
    ) {
        if (text == null || text.length() < 4) {
            return null;
        }
        String lower = text.toLowerCase(TR);
        for (String particle : QUESTION_PARTICLES) {
            if (lower.length() <= particle.length() + 2) {
                continue;
            }
            if (!lower.endsWith(particle)) {
                continue;
            }
            String head = text.substring(0, text.length() - particle.length());
            if (head.length() < 2) {
                continue;
            }
            String fixedHead = fixHeadForPeel(head, morphology, spellChecker);
            if (fixedHead == null) {
                continue;
            }
            // Pronoun / allowlisted discourse host / finite verb only.
            // Ordinary nouns (oda, araba, kitabı) stay glued (odamı, kitabımı).
            if (!isSafeQuestionPeelHost(morphology, fixedHead)
                    && !isSafeQuestionPeelHost(morphology, head)) {
                continue;
            }
            return fixedHead + " " + particle.toLowerCase(TR);
        }
        return null;
    }

    /**
     * Safe hosts for spell-OK question peels: pronouns, finite verbs, or allowlisted
     * discourse/tag-question words. Ordinary nouns (oda, araba) are rejected.
     */
    private static boolean isSafeQuestionPeelHost(TurkishMorphology morphology, String head) {
        if (head == null || head.isEmpty()) {
            return false;
        }
        String lower = head.toLowerCase(TR);
        if (QUESTION_PEEL_HOST_ALLOWLIST.contains(lower)) {
            return true;
        }
        if (hasPronounAnalysis(morphology, head)) {
            return true;
        }
        // Finite / content verbs make natural tag-question hosts (geliyor mu).
        return hasContentVerbAnalysis(morphology, head);
    }

    private static boolean hasPronounAnalysis(TurkishMorphology morphology, String word) {
        WordAnalysis analysis = morphology.analyze(word);
        for (SingleAnalysis single : analysis) {
            if (single.isUnknown()) {
                continue;
            }
            if (single.getDictionaryItem().primaryPos == PrimaryPos.Pronoun) {
                return true;
            }
            SecondaryPos spos = single.getDictionaryItem().secondaryPos;
            if (spos != null) {
                String name = spos.name();
                if (name != null && name.contains("Pron")) {
                    return true;
                }
            }
        }
        return false;
    }

    private static boolean hasContentVerbAnalysis(TurkishMorphology morphology, String word) {
        WordAnalysis analysis = morphology.analyze(word);
        for (SingleAnalysis single : analysis) {
            if (single.isUnknown()) {
                continue;
            }
            if (single.getDictionaryItem().primaryPos != PrimaryPos.Verb) {
                continue;
            }
            // Skip bare imperative-looking analyses (var -> varmak Imp) without tense/aspect.
            boolean tenseOrPerson = false;
            for (Morpheme morpheme : single.getMorphemes()) {
                String id = morpheme.id;
                if (id == null) {
                    continue;
                }
                if (id.startsWith("Past") || id.startsWith("Pres") || id.startsWith("Fut")
                        || id.startsWith("Prog") || id.startsWith("Aor") || id.startsWith("Neces")
                        || id.startsWith("Cond") || id.startsWith("Opt") || id.startsWith("Desr")
                        || id.equals("A1sg") || id.equals("A2sg") || id.equals("A3sg")
                        || id.equals("A1pl") || id.equals("A2pl") || id.equals("A3pl")
                        || id.equals("Neg")) {
                    tenseOrPerson = true;
                    break;
                }
            }
            if (tenseOrPerson) {
                return true;
            }
        }
        return false;
    }

    /**
     * Right-to-left recursive peel of question particles and conjunction clitics.
     * Example: Bursayadamı -> Bursa'ya da mı
     */
    private static String tryPeelConnectedParticles(
            String text,
            TurkishMorphology morphology,
            TurkishSpellChecker spellChecker
    ) {
        if (text == null || text.length() < 5) {
            return null;
        }
        // Spell-OK regular words: only allow the narrow glued-question peel (pronoun/host),
        // never split solid forms like kitapta / odamı via the general multi-clitic peeler.
        if (spellChecker.check(text) && hasRegularAnalysis(morphology, text)) {
            return tryPeelGluedQuestionParticle(text, morphology, spellChecker);
        }
        String peeled = peelConnectedParticles(text, morphology, spellChecker, 0);
        if (peeled == null || peeled.equals(text) || peeled.indexOf(' ') < 0) {
            return null;
        }
        return peeled;
    }

    private static String peelConnectedParticles(
            String text,
            TurkishMorphology morphology,
            TurkishSpellChecker spellChecker,
            int depth
    ) {
        if (text == null || text.isEmpty() || depth > 4) {
            return null;
        }

        String lower = text.toLowerCase(TR);

        // 1) Longest question particle first.
        for (String particle : QUESTION_PARTICLES) {
            if (lower.length() <= particle.length() + 2) {
                continue;
            }
            if (!lower.endsWith(particle)) {
                continue;
            }
            String head = text.substring(0, text.length() - particle.length());
            String combined = combinePeel(head, particle, morphology, spellChecker, depth);
            if (combined != null) {
                return combined;
            }
        }

        // 2) Conjunction / relative clitics.
        for (String clitic : CLITICS) {
            if (lower.length() <= clitic.length() + 3) {
                continue;
            }
            if (!lower.endsWith(clitic)) {
                continue;
            }
            String head = text.substring(0, text.length() - clitic.length());
            String combined = combinePeel(head, clitic, morphology, spellChecker, depth);
            if (combined != null) {
                return combined;
            }
        }

        return null;
    }

    private static String combinePeel(
            String head,
            String tail,
            TurkishMorphology morphology,
            TurkishSpellChecker spellChecker,
            int depth
    ) {
        if (head == null || head.length() < 2) {
            return null;
        }

        // Recurse into residual head first (Bursayada -> Bursa'ya da).
        String deeper = peelConnectedParticles(head, morphology, spellChecker, depth + 1);
        if (deeper != null && deeper.indexOf(' ') >= 0) {
            return deeper + " " + tail.toLowerCase(TR);
        }

        String fixedHead = fixHeadForPeel(head, morphology, spellChecker);
        if (fixedHead == null) {
            return null;
        }
        return fixedHead + " " + tail.toLowerCase(TR);
    }

    /**
     * Residual-head fixer used after clitic/question peels.
     * Allows lowercase place names to become Bursa'ya / Denizli'ye, but does not
     * rewrite ordinary regular words into false proper nouns (sandınız -/-> Sand'ınız).
     * Intentionally avoids spell-suggestions on residuals so typos like Yaaptıklarımı
     * fall through to whole-word spell correction instead of "Yaptıkları mı".
     */
    private static String fixHeadForPeel(
            String head,
            TurkishMorphology morphology,
            TurkishSpellChecker spellChecker
    ) {
        if (head == null || head.isEmpty()) {
            return null;
        }

        String proper = tryFormatProperNoun(head, morphology, true);
        if (proper != null && hasApostrophe(proper) && shouldAcceptProperPeelHead(head, morphology, spellChecker)) {
            return proper;
        }

        if (isAcceptableWord(morphology, spellChecker, head)) {
            return head;
        }

        String deHead = safeDeasciify(head);
        if (deHead != null && !deHead.equals(head)) {
            String properDe = tryFormatProperNoun(deHead, morphology, true);
            if (properDe != null && hasApostrophe(properDe)
                    && shouldAcceptProperPeelHead(deHead, morphology, spellChecker)) {
                return properDe;
            }
            if (isAcceptableWord(morphology, spellChecker, deHead)) {
                return preserveCase(head, deHead);
            }
        }

        return null;
    }

    /**
     * Accept proper-with-apostrophe residual when it is a real place/person suffix form,
     * not a regular word that happens to have a weak proper analysis.
     */
    private static boolean shouldAcceptProperPeelHead(
            String head,
            TurkishMorphology morphology,
            TurkishSpellChecker spellChecker
    ) {
        if (head == null || head.isEmpty()) {
            return false;
        }
        if (Character.isUpperCase(head.charAt(0)) || hasApostrophe(head)) {
            return true;
        }
        // Lowercase residual: accept proper form only if the head is not already a
        // solid regular dictionary word (prevents sandınız -> Sand'ınız).
        if (spellChecker.check(head) && hasRegularAnalysis(morphology, head)) {
            return false;
        }
        return true;
    }

    private static String fixHead(
            String head,
            TurkishMorphology morphology,
            TurkishSpellChecker spellChecker,
            boolean forceProper
    ) {
        if (isAcceptableWord(morphology, spellChecker, head)) {
            if (forceProper || Character.isUpperCase(head.charAt(0)) || hasApostrophe(head)) {
                String proper = tryFormatProperNoun(head, morphology, forceProper);
                if (proper != null) {
                    return proper;
                }
            }
            return head;
        }
        String proper = tryFormatProperNoun(head, morphology, forceProper);
        if (proper != null) {
            return proper;
        }
        String deHead = safeDeasciify(head);
        if (deHead != null && !deHead.equals(head) && isAcceptableWord(morphology, spellChecker, deHead)) {
            String properDe = tryFormatProperNoun(deHead, morphology, forceProper);
            return properDe != null ? properDe : preserveCase(head, deHead);
        }
        return null;
    }

    /**
     * Format unidentified / proper-noun surfaces with apostrophe when safe.
     *
     * @param allowDespiteRegular when true, allow Denizliye -> Denizli'ye even if a weak regular analysis exists
     */
    private static String tryFormatProperNoun(
            String text,
            TurkishMorphology morphology,
            boolean allowDespiteRegular
    ) {
        if (text == null || text.isEmpty()) {
            return null;
        }

        boolean hasRegular = hasRegularAnalysis(morphology, text);
        if (hasRegular && !allowDespiteRegular) {
            // Lowercase regular words must not become Sand'ınız / Bilir'dik / Olur.
            if (!Character.isUpperCase(text.charAt(0))) {
                return null;
            }
        }

        WordAnalysis analysis = morphology.analyze(text);
        SingleAnalysis best = null;
        int bestScore = Integer.MIN_VALUE;
        for (SingleAnalysis single : analysis) {
            if (single.isUnknown()) {
                continue;
            }
            SecondaryPos spos = single.getDictionaryItem().secondaryPos;
            if (spos != SecondaryPos.ProperNoun && spos != SecondaryPos.Abbreviation) {
                continue;
            }
            String formatted = FORMATTER.formatToCase(
                    single,
                    WordAnalysisSurfaceFormatter.CaseType.TITLE_CASE
            );
            if (formatted == null || formatted.isEmpty()) {
                continue;
            }
            // Require a real apostrophe improvement (Allah'ın, Denizli'ye). Plain "Boyle" is rejected.
            if (!hasApostrophe(formatted)) {
                continue;
            }
            String strippedFmt = stripApostrophes(formatted).toLowerCase(TR);
            String strippedText = stripApostrophes(text).toLowerCase(TR);
            if (!strippedFmt.equals(strippedText)) {
                continue;
            }

            int score = 50;
            if (spos == SecondaryPos.ProperNoun) {
                score += 20;
            }
            if (!single.isRuntime()) {
                score += 5;
            }
            String ending = single.getEnding();
            if (ending != null && !ending.isEmpty()) {
                score += 5;
            }
            // Prefer longer lemmas (Denizli over Deniz).
            String lemma = single.getDictionaryItem().lemma;
            if (lemma != null) {
                score += Math.min(lemma.length(), 12);
            }
            if (score > bestScore) {
                bestScore = score;
                best = single;
            }
        }
        if (best == null) {
            return null;
        }
        String formatted = FORMATTER.formatToCase(
                best,
                WordAnalysisSurfaceFormatter.CaseType.TITLE_CASE
        );
        if (formatted.equals(text)) {
            return null;
        }
        return formatted;
    }

    private static boolean isLikelyProperSurface(String text, TurkishMorphology morphology) {
        if (text == null || text.isEmpty()) {
            return false;
        }
        if (hasApostrophe(text) && Character.isUpperCase(text.charAt(0))) {
            return true;
        }
        // Only treat as proper for casing if analysis is proper-dominant (no regular analysis).
        if (hasRegularAnalysis(morphology, text)) {
            return false;
        }
        WordAnalysis analysis = morphology.analyze(text);
        for (SingleAnalysis single : analysis) {
            if (single.isUnknown()) {
                continue;
            }
            SecondaryPos spos = single.getDictionaryItem().secondaryPos;
            if (spos == SecondaryPos.ProperNoun || spos == SecondaryPos.Abbreviation) {
                return true;
            }
        }
        return false;
    }

    private static boolean hasRegularAnalysis(TurkishMorphology morphology, String text) {
        WordAnalysis analysis = morphology.analyze(text);
        for (SingleAnalysis single : analysis) {
            if (single.isUnknown() || single.isRuntime()) {
                continue;
            }
            SecondaryPos spos = single.getDictionaryItem().secondaryPos;
            if (spos != SecondaryPos.ProperNoun && spos != SecondaryPos.Abbreviation) {
                return true;
            }
        }
        return false;
    }

    private static String tryDeasciify(
            String text,
            TurkishMorphology morphology,
            TurkishSpellChecker spellChecker
    ) {
        String converted = safeDeasciify(text);
        if (converted == null || converted.equals(text)) {
            return null;
        }
        if (spellChecker.check(converted) || isMorphologicallyKnown(morphology, converted)) {
            // Keep deasciified regular word; do not force proper title case.
            if (Character.isUpperCase(text.charAt(0))) {
                String proper = tryFormatProperNoun(converted, morphology, false);
                if (proper != null) {
                    return proper;
                }
                return preserveCase(text, converted);
            }
            return converted.toLowerCase(TR);
        }
        return null;
    }

    private static String safeDeasciify(String text) {
        try {
            String converted = Deasciifier.deasciify(text);
            if (converted == null || converted.isEmpty()) {
                return null;
            }
            return converted;
        } catch (Exception ignored) {
            return null;
        }
    }

    private static String pickBestSuggestion(
            String original,
            List<String> suggestions,
            TurkishMorphology morphology,
            TurkishSpellChecker spellChecker
    ) {
        String best = null;
        int bestScore = Integer.MIN_VALUE;

        for (String candidate : suggestions) {
            if (candidate == null || candidate.isEmpty()) {
                continue;
            }
            if (candidate.indexOf('?') >= 0) {
                continue;
            }
            if (!isSafeTurkishToken(candidate)) {
                continue;
            }
            if (!isAcceptableWord(morphology, spellChecker, candidate)) {
                continue;
            }

            String origNorm = stripApostrophes(original).toLowerCase(TR);
            String candNorm = stripApostrophes(candidate).toLowerCase(TR);
            int distance = levenshtein(origNorm, candNorm);
            boolean onlyApostrophe = distance == 0 && !original.equals(candidate);
            if (distance == 0 && !onlyApostrophe) {
                continue;
            }
            if (distance > Math.max(2, original.length() / 3)) {
                continue;
            }

            int score = 100;
            score -= distance * 12;
            score -= Math.abs(candNorm.length() - origNorm.length()) * 4;

            boolean originalHasApos = hasApostrophe(original);
            boolean candidateHasApos = hasApostrophe(candidate);
            if (!originalHasApos && candidateHasApos) {
                if (onlyApostrophe && Character.isUpperCase(original.charAt(0))) {
                    score += 10;
                } else if (onlyApostrophe) {
                    score -= 5;
                } else {
                    score -= 30;
                }
            }

            if (Character.isUpperCase(original.charAt(0)) == Character.isUpperCase(candidate.charAt(0))) {
                score += 5;
            }
            if (stripDiacritics(origNorm).equals(stripDiacritics(candNorm))) {
                score += 25;
            }
            if (spellChecker.check(candidate)) {
                score += 8;
            }
            // Prefer regular dictionary words over proper-noun spell guesses.
            if (hasRegularAnalysis(morphology, candidate)) {
                score += 6;
            }

            if (score > bestScore) {
                bestScore = score;
                best = candidate;
            }
        }

        if (bestScore < 70 || best == null) {
            return null;
        }
        // Preserve original casing style for non-proper suggestions.
        if (Character.isUpperCase(original.charAt(0)) && !hasApostrophe(best)) {
            return preserveCase(original, best);
        }
        if (hasApostrophe(best)) {
            return best;
        }
        return best.toLowerCase(TR);
    }

    private static String polishText(String text, TurkishMorphology morphology) {
        if (text == null || text.isEmpty()) {
            return text;
        }
        // Number separators before sentence split so "1,500.75" is not cut at '.'.
        String normalized = applyTurkishNumberSeparators(text);
        List<String> sentences = splitSentences(normalized);
        StringBuilder out = new StringBuilder();
        for (String sentence : sentences) {
            String polished = polishSentence(sentence.trim(), morphology);
            if (polished.isEmpty()) {
                continue;
            }
            if (out.length() > 0) {
                out.append(' ');
            }
            out.append(polished);
        }
        return out.toString();
    }

    /**
     * Split on sentence-final . ! ? … but not ordinals (3.), decimals, dates (15.06.2026),
     * times (14.30), punctuation inside quotes, or periods before a closing quote + reporting verb.
     */
    private static List<String> splitSentences(String text) {
        List<String> result = new ArrayList<>();
        StringBuilder current = new StringBuilder();
        int quoteDepth = 0;
        for (int i = 0; i < text.length(); i++) {
            char c = text.charAt(i);
            current.append(c);
            if (c == '"' || c == '“' || c == '”' || c == '«' || c == '»') {
                // Toggle-style depth for straight quotes; directional quotes open/close.
                if (c == '“' || c == '«') {
                    quoteDepth++;
                } else if (c == '”' || c == '»') {
                    quoteDepth = Math.max(0, quoteDepth - 1);
                } else {
                    quoteDepth = quoteDepth == 0 ? 1 : 0;
                }
                continue;
            }
            if (quoteDepth > 0) {
                continue; // never split inside quotes
            }
            if (c == '!' || c == '?' || c == '…') {
                // Also suppress split when ?/! is immediately followed by closing quote.
                int k = i + 1;
                while (k < text.length() && Character.isWhitespace(text.charAt(k))) {
                    k++;
                }
                if (k < text.length() && isClosingQuoteChar(text.charAt(k))) {
                    continue;
                }
                result.add(current.toString());
                current.setLength(0);
            } else if (c == '.' && isSentenceFinalPeriod(text, i)) {
                result.add(current.toString());
                current.setLength(0);
            }
        }
        if (current.length() > 0) {
            result.add(current.toString());
        }
        if (result.isEmpty()) {
            result.add(text);
        }
        return result;
    }

    private static boolean isSentenceFinalPeriod(String text, int index) {
        if (index < 0 || index >= text.length() || text.charAt(index) != '.') {
            return false;
        }
        char prev = index > 0 ? text.charAt(index - 1) : 0;
        char next = index + 1 < text.length() ? text.charAt(index + 1) : 0;
        if (next == '.' || prev == '.') {
            return false;
        }
        if (Character.isDigit(prev) && Character.isDigit(next)) {
            return false;
        }
        int k = index + 1;
        while (k < text.length() && Character.isWhitespace(text.charAt(k))) {
            k++;
        }
        char after = k < text.length() ? text.charAt(k) : 0;
        if (Character.isDigit(prev)) {
            if (after == 0) {
                return true;
            }
            if (Character.isDigit(after)) {
                return false;
            }
            if (isClosingQuoteChar(after)) {
                return false;
            }
            // "3. sırada" — ordinal, not sentence end.
            if (Character.isLetter(after) && Character.isLowerCase(after)) {
                return false;
            }
            return Character.isLetter(after) && Character.isUpperCase(after)
                    || after == '"' || after == '“' || after == '«';
        }
        if (isClosingQuoteChar(after)) {
            return false;
        }
        return true;
    }

    private static String polishSentence(String sentence, TurkishMorphology morphology) {
        if (sentence.isEmpty()) {
            return sentence;
        }

        char terminal = 0;
        String body = sentence;
        char last = sentence.charAt(sentence.length() - 1);
        if (last == '.' || last == '!' || last == '?' || last == '…') {
            terminal = last;
            body = sentence.substring(0, sentence.length() - 1);
        }

        List<Token> tokens = TurkishTokenizer.DEFAULT.tokenize(body.trim());
        if (tokens.isEmpty()) {
            return sentence.trim();
        }

        List<String> words = new ArrayList<>();
        List<Token.Type> types = new ArrayList<>();
        for (Token token : tokens) {
            words.add(token.getText());
            types.add(token.getType());
        }

        // elon.io punctuation conventions.
        insertMissingClauseCommas(words, types, morphology);
        insertDiscourseOpenerCommas(words, types);
        removeOxfordCommaBeforeVe(words, types);
        fixQuoteReportingPunctuation(words, types);

        // Question terminal only from free mi/mu outside quotes (not "… mi?" quotes).
        boolean hasQuestionParticle = hasFreeQuestionParticleOutsideQuotes(words, types);
        if (terminal == '.' && hasQuestionParticle) {
            terminal = '?';
        } else if (terminal == 0 && hasQuestionParticle) {
            terminal = '?';
        } else if (terminal == 0) {
            terminal = '.';
        }

        boolean sentenceStart = true;
        int quoteDepth = 0;
        int lastQuoteRole = 0; // 1 open, -1 close
        StringBuilder out = new StringBuilder();
        for (int i = 0; i < words.size(); i++) {
            String word = words.get(i);
            Token.Type type = types.get(i);
            String next = word;
            Token.Type prevType = i > 0 ? types.get(i - 1) : null;
            String prevWord = i > 0 ? words.get(i - 1) : null;

            boolean ambiguousQuote = "\"".equals(word) || "'".equals(word);
            boolean openQ = isOpeningQuoteOnly(word) || (ambiguousQuote && quoteDepth == 0);
            boolean closeQ = isClosingQuoteOnly(word) || (ambiguousQuote && quoteDepth > 0);

            if (type == Token.Type.Word || type == Token.Type.WordWithSymbol
                    || type == Token.Type.WordAlphanumerical) {
                next = adjustWordCase(word, sentenceStart, morphology);
                sentenceStart = false;
            } else if (type == Token.Type.Number || type == Token.Type.Time
                    || type == Token.Type.Date || type == Token.Type.PercentNumeral) {
                sentenceStart = false;
            } else if (word.trim().isEmpty()) {
                continue;
            }

            if (shouldPrependSpaceTurkish(out, next, type, prevType, prevWord, openQ, closeQ, lastQuoteRole)) {
                out.append(' ');
            }
            out.append(next);

            if (openQ) {
                quoteDepth++;
                lastQuoteRole = 1;
            } else if (closeQ) {
                if (quoteDepth > 0) {
                    quoteDepth--;
                }
                lastQuoteRole = -1;
            } else if (",".equals(next) || ".".equals(next) || "!".equals(next) || "?".equals(next)) {
                // keep lastQuoteRole for ", dedi" after moved comma
            } else if (type != Token.Type.Punctuation) {
                lastQuoteRole = 0;
            }
        }

        String polished = out.toString().trim();
        polished = applyTurkishNumberSeparators(polished);
        if (terminal != 0) {
            polished = polished + terminal;
        }
        return polished;
    }

    private static boolean hasFreeQuestionParticleOutsideQuotes(
            List<String> words,
            List<Token.Type> types
    ) {
        int quoteDepth = 0;
        for (int i = 0; i < words.size(); i++) {
            String w = words.get(i);
            boolean ambiguous = "\"".equals(w) || "'".equals(w);
            if (isOpeningQuoteOnly(w) || (ambiguous && quoteDepth == 0)) {
                quoteDepth++;
                continue;
            }
            if (isClosingQuoteOnly(w) || (ambiguous && quoteDepth > 0)) {
                quoteDepth = Math.max(0, quoteDepth - 1);
                continue;
            }
            if (quoteDepth > 0) {
                continue;
            }
            Token.Type type = types.get(i);
            if (type == Token.Type.Word || type == Token.Type.WordWithSymbol) {
                if (QUESTION_PARTICLE_SET.contains(w.toLowerCase(TR))) {
                    return true;
                }
            }
        }
        return false;
    }

    /** "Evet seninle..." -> "Evet, seninle..." */
    private static void insertDiscourseOpenerCommas(List<String> words, List<Token.Type> types) {
        if (words == null || words.isEmpty()) {
            return;
        }
        int i = 0;
        while (i < words.size() && isOpeningWrapper(words.get(i))) {
            i++;
        }
        if (i >= words.size() || !isWordishType(types.get(i))) {
            return;
        }
        if (!DISCOURSE_OPENER_COMMA.contains(words.get(i).toLowerCase(TR))) {
            return;
        }
        int next = nextNonSpaceIndex(words, i + 1);
        if (next < 0 || !isWordishType(types.get(next))) {
            return;
        }
        if (",".equals(words.get(next)) || isPunctuationText(words.get(next))) {
            return;
        }
        words.add(i + 1, ",");
        types.add(i + 1, Token.Type.Punctuation);
    }

    /** No Oxford comma: "yumurta, ve peynir" -> "yumurta ve peynir". */
    private static void removeOxfordCommaBeforeVe(List<String> words, List<Token.Type> types) {
        if (words == null || words.size() < 3) {
            return;
        }
        for (int i = 0; i < words.size(); i++) {
            if (!",".equals(words.get(i))) {
                continue;
            }
            int next = nextNonSpaceIndex(words, i + 1);
            if (next < 0 || !isWordishType(types.get(next))) {
                continue;
            }
            String n = words.get(next).toLowerCase(TR);
            boolean veFamily = "ve".equals(n) || "veya".equals(n) || "yahut".equals(n);
            if (!veFamily && "ya".equals(n)) {
                int n2 = nextNonSpaceIndex(words, next + 1);
                if (n2 >= 0 && isWordishType(types.get(n2)) && "da".equals(words.get(n2).toLowerCase(TR))) {
                    veFamily = true;
                }
            }
            if (!veFamily) {
                continue;
            }
            int prev = previousNonSpaceIndex(words, types, i - 1);
            if (prev < 0 || !isWordishType(types.get(prev))) {
                continue;
            }
            words.remove(i);
            types.remove(i);
            i--;
        }
    }

    /**
     * Quote + reporting verb (elon.io):
     * "... var." dedi -> "... var" dedi
     * "... kaldım," diyerek -> "... kaldım", diyerek
     * Keep ?/! inside the quote.
     */
    private static void fixQuoteReportingPunctuation(List<String> words, List<Token.Type> types) {
        if (words == null || words.size() < 4) {
            return;
        }
        for (int close = 0; close < words.size(); close++) {
            if (!isClosingQuoteToken(words.get(close), close, words)) {
                continue;
            }
            int open = findMatchingOpenQuote(words, close);
            if (open < 0) {
                continue;
            }
            int after = nextNonSpaceIndex(words, close + 1);
            if (after < 0 || !isWordishType(types.get(after))) {
                continue;
            }
            if (!isQuoteReportingContinuer(words.get(after))) {
                continue;
            }
            int beforeClose = previousNonSpaceIndex(words, types, close - 1);
            if (beforeClose <= open) {
                continue;
            }
            String inner = words.get(beforeClose);
            if (".".equals(inner)) {
                words.remove(beforeClose);
                types.remove(beforeClose);
                close--;
            } else if (",".equals(inner)) {
                words.remove(beforeClose);
                types.remove(beforeClose);
                int newClose = close - 1;
                words.add(newClose + 1, ",");
                types.add(newClose + 1, Token.Type.Punctuation);
                close = newClose + 1;
            }
        }
    }

    private static boolean isQuoteReportingContinuer(String word) {
        if (word == null) {
            return false;
        }
        String lower = word.toLowerCase(TR);
        if (QUOTE_REPORTING_CONTINUERS.contains(lower)) {
            return true;
        }
        return lower.startsWith("dedi") || lower.startsWith("demiş") || lower.startsWith("diyor")
                || lower.startsWith("diye") || lower.startsWith("sordu") || lower.startsWith("sormuş")
                || lower.startsWith("söyled") || lower.startsWith("soyled");
    }

    private static boolean isClosingQuoteToken(String token, int index, List<String> words) {
        if (isClosingQuoteOnly(token)) {
            return true;
        }
        if (!"\"".equals(token) && !"'".equals(token)) {
            return false;
        }
        // Ambiguous straight quote: closing if an unmatched open exists before.
        int depth = 0;
        for (int i = 0; i <= index; i++) {
            String t = words.get(i);
            if (isOpeningQuoteOnly(t)) {
                depth++;
            } else if (isClosingQuoteOnly(t)) {
                depth = Math.max(0, depth - 1);
            } else if ("\"".equals(t) || "'".equals(t)) {
                if (depth == 0) {
                    depth = 1;
                } else {
                    depth = 0;
                }
            }
        }
        return depth == 0 && index > 0;
    }

    private static int findMatchingOpenQuote(List<String> words, int closeIndex) {
        String close = words.get(closeIndex);
        String want = matchingOpenQuote(close);
        for (int i = closeIndex - 1; i >= 0; i--) {
            String t = words.get(i);
            if (want != null && want.equals(t)) {
                return i;
            }
            if (isOpeningQuoteOnly(t) || "\"".equals(t) || "'".equals(t)) {
                return i;
            }
        }
        return -1;
    }

    private static String matchingOpenQuote(String close) {
        if ("\"".equals(close)) {
            return "\"";
        }
        if ("”".equals(close)) {
            return "“";
        }
        if ("»".equals(close)) {
            return "«";
        }
        if ("’".equals(close) || "'".equals(close)) {
            return "'";
        }
        return null;
    }

    private static boolean isOpeningQuoteOnly(String t) {
        return "“".equals(t) || "«".equals(t) || "‘".equals(t);
    }

    private static boolean isClosingQuoteOnly(String t) {
        return "”".equals(t) || "»".equals(t) || "’".equals(t);
    }

    private static boolean isOpeningWrapper(String t) {
        return isOpeningQuoteOnly(t) || "\"".equals(t) || "'".equals(t)
                || "(".equals(t) || "[".equals(t) || "{".equals(t);
    }

    private static boolean isClosingQuoteChar(char c) {
        return c == '"' || c == '”' || c == '»' || c == '\'' || c == '’';
    }

    private static int nextNonSpaceIndex(List<String> words, int from) {
        for (int i = from; i < words.size(); i++) {
            String w = words.get(i);
            if (w != null && !w.trim().isEmpty()) {
                return i;
            }
        }
        return -1;
    }

    /**
     * Turkish spacing: glue number parts; no space after opening quote;
     * space after closing quote before continuer; space after list commas.
     */
    private static boolean shouldPrependSpaceTurkish(
            StringBuilder out,
            String next,
            Token.Type type,
            Token.Type prevType,
            String prevWord,
            boolean tokenIsOpeningQuote,
            boolean tokenIsClosingQuote,
            int lastQuoteRole
    ) {
        if (out.length() == 0 || next == null || next.isEmpty()) {
            return false;
        }
        char last = out.charAt(out.length() - 1);
        char first = next.charAt(0);

        // No space before closing quote / closing punct, even after ?/! inside the quote.
        if (tokenIsClosingQuote
                || first == ',' || first == '.' || first == '!' || first == '?'
                || first == ':' || first == ';' || first == '…'
                || first == ')' || first == ']' || first == '}') {
            return false;
        }
        // mi?" — no space between inner terminal punct and a CLOSING quote only.
        if ((last == '?' || last == '!' || last == '.')
                && (tokenIsClosingQuote || isClosingQuoteOnly(next))) {
            return false;
        }
        if (tokenIsOpeningQuote || first == '(' || first == '[' || first == '{') {
            if (lastQuoteRole == 1 || last == '(' || last == '[') {
                return false;
            }
            return Character.isLetterOrDigit(last) || last == ',' || last == ';' || last == ':';
        }
        if (lastQuoteRole == 1 || last == '(' || last == '[' || last == '{') {
            return false;
        }

        boolean prevNumeric = prevType == Token.Type.Number || prevType == Token.Type.Time
                || prevType == Token.Type.Date || prevType == Token.Type.PercentNumeral
                || (prevWord != null && endsWithDigit(prevWord));
        boolean nextNumeric = type == Token.Type.Number || type == Token.Type.Time
                || type == Token.Type.Date || type == Token.Type.PercentNumeral
                || startsWithDigit(next);
        if (prevNumeric && next.length() == 1 && (first == '.' || first == ',' || first == ':')) {
            return false;
        }
        if ((last == '.' || last == ',' || last == ':') && nextNumeric) {
            return false;
        }
        if (prevNumeric && last == '.' && Character.isLetter(first)) {
            return true;
        }
        if (lastQuoteRole == -1 || last == ')' || last == ']') {
            return Character.isLetterOrDigit(first);
        }
        if (last == ',' || last == ';' || last == ':') {
            return Character.isLetterOrDigit(first) || tokenIsOpeningQuote || isOpeningQuoteOnly(next);
        }
        return Character.isLetterOrDigit(first)
                || tokenIsOpeningQuote
                || isOpeningQuoteOnly(next)
                || first == '(' || first == '[' || first == '{';
    }

    private static boolean endsWithDigit(String s) {
        return s != null && !s.isEmpty() && Character.isDigit(s.charAt(s.length() - 1));
    }

    private static boolean startsWithDigit(String s) {
        return s != null && !s.isEmpty() && Character.isDigit(s.charAt(0));
    }

    /**
     * English -> Turkish number separators:
     * 1,500.75 -> 1.500,75 ; 3.14'tür -> 3,14'tür
     * Leaves dates, times, ordinals, and already-TR forms alone.
     */
    private static String applyTurkishNumberSeparators(String text) {
        if (text == null || text.isEmpty()) {
            return text;
        }
        String out = text;

        Matcher m = ENG_THOUSANDS_NUMBER.matcher(out);
        StringBuffer sb = new StringBuffer();
        while (m.find()) {
            String whole = m.group(1).replace(",", ".");
            String frac = m.group(2);
            String repl = frac == null ? whole : whole + "," + frac;
            m.appendReplacement(sb, Matcher.quoteReplacement(repl));
        }
        m.appendTail(sb);
        out = sb.toString();

        m = ENG_DECIMAL_BEFORE_SUFFIX.matcher(out);
        sb = new StringBuffer();
        while (m.find()) {
            String ip = m.group(1);
            String fp = m.group(2);
            String suffix = m.group(3);
            if (looksLikeClockTime(ip, fp) && isTimeLikeApostropheSuffix(suffix.toLowerCase(TR))) {
                m.appendReplacement(sb, Matcher.quoteReplacement(m.group(0)));
            } else {
                m.appendReplacement(sb, Matcher.quoteReplacement(ip + "," + fp + "'" + suffix));
            }
        }
        m.appendTail(sb);
        out = sb.toString();

        m = ENG_DECIMAL_BEFORE_UNIT.matcher(out);
        sb = new StringBuffer();
        while (m.find()) {
            m.appendReplacement(sb, Matcher.quoteReplacement(m.group(1) + "," + m.group(2)));
        }
        m.appendTail(sb);
        out = sb.toString();

        m = ENG_DECIMAL_SAFE.matcher(out);
        sb = new StringBuffer();
        while (m.find()) {
            String ip = m.group(1);
            String fp = m.group(2);
            if (shouldConvertPlainEnglishDecimal(out, m.start(), ip, fp)) {
                m.appendReplacement(sb, Matcher.quoteReplacement(ip + "," + fp));
            } else {
                m.appendReplacement(sb, Matcher.quoteReplacement(m.group(0)));
            }
        }
        m.appendTail(sb);
        return sb.toString();
    }

    private static boolean looksLikeClockTime(String integerPart, String fracPart) {
        if (integerPart == null || fracPart == null || fracPart.length() != 2) {
            return false;
        }
        try {
            int h = Integer.parseInt(integerPart);
            int min = Integer.parseInt(fracPart);
            return h >= 0 && h <= 23 && min >= 0 && min <= 59 && integerPart.length() <= 2;
        } catch (NumberFormatException e) {
            return false;
        }
    }

    private static boolean isTimeLikeApostropheSuffix(String suffixLower) {
        if (suffixLower == null || suffixLower.isEmpty()) {
            return false;
        }
        return suffixLower.startsWith("da") || suffixLower.startsWith("de")
                || suffixLower.startsWith("ta") || suffixLower.startsWith("te")
                || suffixLower.startsWith("dan") || suffixLower.startsWith("den")
                || suffixLower.startsWith("tan") || suffixLower.startsWith("ten")
                || suffixLower.equals("a") || suffixLower.equals("e")
                || suffixLower.startsWith("ya") || suffixLower.startsWith("ye");
    }

    private static boolean shouldConvertPlainEnglishDecimal(
            String full, int start, String integerPart, String fracPart
    ) {
        if (integerPart == null || fracPart == null || fracPart.isEmpty()) {
            return false;
        }
        int from = Math.max(0, start - 3);
        int to = Math.min(full.length(), start + integerPart.length() + 1 + fracPart.length() + 6);
        String window = full.substring(from, to);
        if (window.matches("(?s).*\\d+\\.\\d+\\.\\d+.*")) {
            return false;
        }
        if (looksLikeClockTime(integerPart, fracPart) && integerPart.length() >= 2) {
            return false;
        }
        if (fracPart.length() == 1 || fracPart.length() >= 3) {
            return true;
        }
        try {
            if (Integer.parseInt(integerPart) > 23) {
                return true;
            }
        } catch (NumberFormatException ignored) {
            return true;
        }
        // Single-digit + 2-frac (3.14): treat as decimal, not clock.
        return integerPart.length() == 1 && fracPart.length() == 2;
    }

    /**
     * Insert a comma before mid-sentence clause connectors when missing.
     * Example: "... davet edebilir aynı zamanda ..." -> "... edebilir, aynı zamanda ..."
     */
    private static void insertMissingClauseCommas(
            List<String> words,
            List<Token.Type> types,
            TurkishMorphology morphology
    ) {
        if (words == null || words.isEmpty() || words.size() != types.size()) {
            return;
        }
        int i = 0;
        boolean seenContent = false;
        while (i < words.size()) {
            Token.Type type = types.get(i);
            if (!isWordishType(type)) {
                if (isSentenceBoundaryPunct(words.get(i))) {
                    seenContent = false;
                }
                i++;
                continue;
            }

            int matchedLen = matchClauseConnector(words, i);
            if (matchedLen > 0 && seenContent) {
                int prev = previousNonSpaceIndex(words, types, i - 1);
                if (prev >= 0 && shouldInsertCommaBeforeConnector(words, types, prev, morphology)) {
                    words.add(i, ",");
                    types.add(i, Token.Type.Punctuation);
                    i += matchedLen + 1;
                    seenContent = true;
                    continue;
                }
            }

            seenContent = true;
            i += Math.max(matchedLen, 1);
        }
    }

    private static boolean isWordishType(Token.Type type) {
        return type == Token.Type.Word
                || type == Token.Type.WordWithSymbol
                || type == Token.Type.WordAlphanumerical
                || type == Token.Type.UnknownWord;
    }

    private static boolean isSentenceBoundaryPunct(String text) {
        if (text == null || text.isEmpty()) {
            return false;
        }
        char c = text.charAt(0);
        return c == '.' || c == '!' || c == '?' || c == '…' || c == ';' || c == ':';
    }

    private static int previousNonSpaceIndex(List<String> words, List<Token.Type> types, int from) {
        for (int i = from; i >= 0; i--) {
            String w = words.get(i);
            if (w == null || w.trim().isEmpty()) {
                continue;
            }
            return i;
        }
        return -1;
    }

    /**
     * Longest connector phrase match at words[index], case-insensitive.
     * @return number of tokens matched, or 0
     */
    private static int matchClauseConnector(List<String> words, int index) {
        int best = 0;
        for (String[] phrase : CLAUSE_COMMA_CONNECTORS) {
            if (phrase.length <= best) {
                continue;
            }
            if (index + phrase.length > words.size()) {
                continue;
            }
            boolean ok = true;
            for (int k = 0; k < phrase.length; k++) {
                String tok = words.get(index + k);
                if (tok == null || !tok.toLowerCase(TR).equals(phrase[k])) {
                    ok = false;
                    break;
                }
            }
            if (ok) {
                best = phrase.length;
            }
        }
        return best;
    }

    private static boolean shouldInsertCommaBeforeConnector(
            List<String> words,
            List<Token.Type> types,
            int prevIndex,
            TurkishMorphology morphology
    ) {
        String prev = words.get(prevIndex);
        Token.Type prevType = types.get(prevIndex);
        if (prev == null || prev.isEmpty()) {
            return false;
        }
        // Already punctuated.
        if (prevType == Token.Type.Punctuation || isPunctuationText(prev)) {
            return false;
        }
        // Do not place comma after opening quotes/brackets.
        char last = prev.charAt(prev.length() - 1);
        if (last == ',' || last == ';' || last == ':' || last == '—' || last == '-' || last == '(' || last == '[' || last == '"' || last == '\'' || last == '“' || last == '‘') {
            return false;
        }
        if (!isWordishType(prevType)) {
            return false;
        }
        // Prefer clause boundary: previous token is a finite/content verb or a
        // verb-like predicate ending (-ebilir, -malı, -yor, past, etc.).
        if (looksLikeClauseEndPredicate(morphology, prev)) {
            return true;
        }
        // Multi-word connectors are strong enough after any content word
        // (..., kısacası / ... bununla birlikte) when not sentence-initial.
        // Single-word ones like "ancak" without a verb host are riskier: skip.
        return false;
    }

    private static boolean isPunctuationText(String text) {
        if (text == null || text.isEmpty()) {
            return false;
        }
        for (int i = 0; i < text.length(); ) {
            int cp = text.codePointAt(i);
            i += Character.charCount(cp);
            if (Character.isLetterOrDigit(cp)) {
                return false;
            }
        }
        return true;
    }

    /**
     * True when the word can close a clause before a connector (finite verb,
     * potential/necessitative forms, copular predicates, etc.).
     */
    private static boolean looksLikeClauseEndPredicate(TurkishMorphology morphology, String word) {
        if (word == null || word.isEmpty()) {
            return false;
        }
        String lower = word.toLowerCase(TR);
        // Fast surface cues common before "aynı zamanda / ancak / çünkü".
        if (lower.endsWith("ebilir") || lower.endsWith("abilir")
                || lower.endsWith("ebilirim") || lower.endsWith("abilirim")
                || lower.endsWith("ebilirsin") || lower.endsWith("abilirsin")
                || lower.endsWith("ebiliriz") || lower.endsWith("abiliriz")
                || lower.endsWith("ebilirsiniz") || lower.endsWith("abilirsiniz")
                || lower.endsWith("ebilirler") || lower.endsWith("abilirler")
                || lower.endsWith("emez") || lower.endsWith("amaz")
                || lower.endsWith("emezsin") || lower.endsWith("amazsın")
                || lower.endsWith("emezsiniz") || lower.endsWith("amazsınız")
                || lower.endsWith("meli") || lower.endsWith("malı")
                || lower.endsWith("melidir") || lower.endsWith("malıdır")
                || lower.endsWith("yor") || lower.endsWith("yoruz") || lower.endsWith("yorsunuz")
                || lower.endsWith("di") || lower.endsWith("dı") || lower.endsWith("du") || lower.endsWith("dü")
                || lower.endsWith("ti") || lower.endsWith("tı") || lower.endsWith("tu") || lower.endsWith("tü")
                || lower.endsWith("miş") || lower.endsWith("mış") || lower.endsWith("muş") || lower.endsWith("müş")
                || lower.endsWith("dir") || lower.endsWith("dır") || lower.endsWith("dur") || lower.endsWith("dür")
                || lower.endsWith("tir") || lower.endsWith("tır") || lower.endsWith("tur") || lower.endsWith("tür")) {
            // Avoid tiny false stems; morphology gate below still runs.
            if (lower.length() >= 4) {
                // continue to morphology for confirmation when possible
            }
        }

        WordAnalysis analysis = morphology.analyze(word);
        boolean any = false;
        for (SingleAnalysis single : analysis) {
            if (single.isUnknown()) {
                continue;
            }
            any = true;
            PrimaryPos pos = single.getDictionaryItem().primaryPos;
            if (pos == PrimaryPos.Verb) {
                if (hasFiniteOrPredicateMorpheme(single)) {
                    return true;
                }
            }
            // Nominal predicates with copula (öğretmendir, hazırız).
            if (hasCopularPredicateMorpheme(single)) {
                return true;
            }
        }
        if (!any) {
            // Unknown but surface looks finite enough.
            return lower.length() >= 5 && (
                    lower.endsWith("ebilir") || lower.endsWith("abilir")
                            || lower.endsWith("ebilirsiniz") || lower.endsWith("abilirsiniz")
                            || lower.endsWith("yor") || lower.endsWith("meli") || lower.endsWith("malı")
            );
        }
        return false;
    }

    private static boolean hasFiniteOrPredicateMorpheme(SingleAnalysis single) {
        boolean tenseAspectMood = false;
        boolean personOrCop = false;
        for (Morpheme morpheme : single.getMorphemes()) {
            String id = morpheme.id;
            if (id == null) {
                continue;
            }
            if (id.startsWith("Past") || id.startsWith("Pres") || id.startsWith("Fut")
                    || id.startsWith("Prog") || id.startsWith("Aor") || id.startsWith("Narr")
                    || id.startsWith("Neces") || id.startsWith("Cond") || id.startsWith("Opt")
                    || id.startsWith("Desr") || id.startsWith("Able") || id.startsWith("Imp")) {
                tenseAspectMood = true;
            }
            if (id.equals("A1sg") || id.equals("A2sg") || id.equals("A3sg")
                    || id.equals("A1pl") || id.equals("A2pl") || id.equals("A3pl")
                    || id.equals("Cop") || id.equals("Neg") || id.equals("Unable")) {
                personOrCop = true;
            }
        }
        return tenseAspectMood || personOrCop;
    }

    private static boolean hasCopularPredicateMorpheme(SingleAnalysis single) {
        for (Morpheme morpheme : single.getMorphemes()) {
            String id = morpheme.id;
            if (id == null) {
                continue;
            }
            if (id.equals("Cop") || id.startsWith("Pres") || id.startsWith("Past")
                    || id.startsWith("Narr") || id.startsWith("Cond")) {
                // Must also look like a zero-verb / person-marked nominal predicate.
                if (id.equals("Cop") || id.equals("A1sg") || id.equals("A2sg") || id.equals("A3sg")
                        || id.equals("A1pl") || id.equals("A2pl") || id.equals("A3pl")) {
                    return true;
                }
            }
            if (id.equals("A1sg") || id.equals("A2sg") || id.equals("A1pl")
                    || id.equals("A2pl") || id.equals("A3pl")) {
                // nominal + person often predicate before connector
                PrimaryPos pos = single.getDictionaryItem().primaryPos;
                if (pos == PrimaryPos.Noun || pos == PrimaryPos.Adjective
                        || pos == PrimaryPos.Pronoun || pos == PrimaryPos.Adverb) {
                    return true;
                }
            }
        }
        return false;
    }

    private static String adjustWordCase(String word, boolean sentenceStart, TurkishMorphology morphology) {
        if (word.isEmpty()) {
            return word;
        }
        // Keep ALL-CAPS acronyms (length > 1).
        if (word.length() > 1 && word.equals(word.toUpperCase(TR)) && !hasApostrophe(word)) {
            return word;
        }

        boolean proper = isLikelyProperSurface(word, morphology);
        if (sentenceStart) {
            if (proper) {
                return capitalizeKeepingApostrophe(word);
            }
            return capitalizeKeepingApostrophe(word);
        }

        // Mid-sentence: lowercase non-proper title-case words (Biz -> biz).
        if (!proper && Character.isUpperCase(word.charAt(0)) && shouldLowercaseMidSentence(word, morphology)) {
            return word.toLowerCase(TR);
        }
        return word;
    }

    private static boolean shouldLowercaseMidSentence(String word, TurkishMorphology morphology) {
        if (isLikelyProperSurface(word, morphology)) {
            return false;
        }
        // Title-case single-word tokens that are regular words.
        WordAnalysis analysis = morphology.analyze(word);
        boolean any = false;
        for (SingleAnalysis single : analysis) {
            if (single.isUnknown()) {
                continue;
            }
            any = true;
            SecondaryPos spos = single.getDictionaryItem().secondaryPos;
            if (spos == SecondaryPos.ProperNoun || spos == SecondaryPos.Abbreviation) {
                // Ambiguous with proper: still lowercase if a regular analysis exists.
                continue;
            }
            return true;
        }
        if (!any) {
            return !hasApostrophe(word);
        }
        // Only proper analyses: keep capital.
        return false;
    }

    private static String capitalizeKeepingApostrophe(String word) {
        if (word.isEmpty()) {
            return word;
        }
        int apostrophe = indexOfApostrophe(word);
        if (apostrophe > 0) {
            String stem = word.substring(0, apostrophe);
            String rest = word.substring(apostrophe);
            return Turkish.capitalize(stem) + rest;
        }
        return Turkish.capitalize(word);
    }

    private static int indexOfApostrophe(String word) {
        int a = word.indexOf('\'');
        int b = word.indexOf('\u2019');
        if (a < 0) {
            return b;
        }
        if (b < 0) {
            return a;
        }
        return Math.min(a, b);
    }

    private static boolean hasApostrophe(String text) {
        return text.indexOf('\'') >= 0 || text.indexOf('\u2019') >= 0;
    }

    private static String stripApostrophes(String text) {
        return text.replace("'", "").replace("\u2019", "");
    }

    private static boolean isSafeTurkishToken(String text) {
        for (int i = 0; i < text.length(); ) {
            int cp = text.codePointAt(i);
            i += Character.charCount(cp);
            if (Character.isLetterOrDigit(cp)) {
                continue;
            }
            if (cp == '\'' || cp == '\u2019' || cp == '-' || cp == '\u2010') {
                continue;
            }
            return false;
        }
        return true;
    }

    private static boolean isAcceptableWord(
            TurkishMorphology morphology,
            TurkishSpellChecker spellChecker,
            String text
    ) {
        if (text == null || text.isEmpty()) {
            return false;
        }
        if (spellChecker.check(text)) {
            return true;
        }
        return isMorphologicallyKnown(morphology, text);
    }

    private static boolean isMorphologicallyKnown(TurkishMorphology morphology, String text) {
        WordAnalysis analysis = morphology.analyze(text);
        for (SingleAnalysis single : analysis) {
            if (!single.isUnknown()) {
                return true;
            }
        }
        return false;
    }

    private static String preserveCase(String original, String candidate) {
        if (original.isEmpty() || candidate.isEmpty()) {
            return candidate;
        }
        if (original.equals(original.toUpperCase(TR))) {
            return candidate.toUpperCase(TR);
        }
        if (Character.isUpperCase(original.charAt(0))) {
            return candidate.substring(0, 1).toUpperCase(TR) + candidate.substring(1);
        }
        return candidate;
    }

    private static String stripDiacritics(String text) {
        return text
                .replace('ç', 'c').replace('Ç', 'C')
                .replace('ğ', 'g').replace('Ğ', 'G')
                .replace('ı', 'i').replace('İ', 'I')
                .replace('ö', 'o').replace('Ö', 'O')
                .replace('ş', 's').replace('Ş', 'S')
                .replace('ü', 'u').replace('Ü', 'U');
    }

    private static int levenshtein(String a, String b) {
        int n = a.length();
        int m = b.length();
        int[] prev = new int[m + 1];
        int[] curr = new int[m + 1];
        for (int j = 0; j <= m; j++) {
            prev[j] = j;
        }
        for (int i = 1; i <= n; i++) {
            curr[0] = i;
            char ca = a.charAt(i - 1);
            for (int j = 1; j <= m; j++) {
                int cost = ca == b.charAt(j - 1) ? 0 : 1;
                curr[j] = Math.min(Math.min(curr[j - 1] + 1, prev[j] + 1), prev[j - 1] + cost);
            }
            int[] tmp = prev;
            prev = curr;
            curr = tmp;
        }
        return prev[m];
    }

    private static boolean shouldPrependSpace(StringBuilder out, String next, Token token) {
        return shouldPrependSpace(out, next, token.getType());
    }

    private static boolean shouldPrependSpace(StringBuilder out, String next, Token.Type type) {
        if (out.length() == 0) {
            return false;
        }
        if (type == Token.Type.Punctuation) {
            return false;
        }
        if (next == null || next.isEmpty()) {
            return false;
        }
        char first = next.charAt(0);
        if (first == ',' || first == '.' || first == ':' || first == ';' || first == '!' || first == '?' || first == '…') {
            return false;
        }
        return true;
    }

    private static String normalizeSpaces(String text) {
        return text.replaceAll("[ \\t\\x0B\\f\\r]+", " ").replaceAll(" *\\n *", "\n").trim();
    }

    private static String readAll() throws Exception {
        BufferedReader reader = new BufferedReader(new InputStreamReader(System.in, StandardCharsets.UTF_8));
        StringBuilder builder = new StringBuilder();
        String line;
        boolean first = true;
        while ((line = reader.readLine()) != null) {
            if (!first) {
                builder.append('\n');
            }
            builder.append(line);
            first = false;
        }
        return builder.toString();
    }

    private static void writeAll(String text) throws Exception {
        BufferedWriter writer = new BufferedWriter(new OutputStreamWriter(System.out, StandardCharsets.UTF_8));
        writer.write(text);
        writer.flush();
    }
}
