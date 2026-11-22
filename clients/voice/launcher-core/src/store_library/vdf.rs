//! Minimal Valve KeyValues (VDF/ACF) parser. Keys compare case-insensitively.

const MAX_DEPTH: usize = 32;

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Node {
    Str(String),
    Block(Vec<(String, Node)>),
}

impl Node {
    #[must_use]
    pub fn get(&self, key: &str) -> Option<&Node> {
        match self {
            Node::Block(items) => items
                .iter()
                .find(|(k, _)| k.eq_ignore_ascii_case(key))
                .map(|(_, v)| v),
            Node::Str(_) => None,
        }
    }

    #[must_use]
    pub fn str(&self) -> Option<&str> {
        match self {
            Node::Str(s) => Some(s),
            Node::Block(_) => None,
        }
    }

    pub fn children(&self) -> impl Iterator<Item = (&str, &Node)> {
        let items: &[(String, Node)] = match self {
            Node::Block(items) => items,
            Node::Str(_) => &[],
        };
        items.iter().map(|(k, v)| (k.as_str(), v))
    }
}

#[derive(Debug, PartialEq)]
enum Tok {
    Text(String),
    Open,
    Close,
}

fn tokenize(text: &str) -> Option<Vec<Tok>> {
    let mut out = Vec::new();
    let mut it = text.chars().peekable();
    while let Some(&c) = it.peek() {
        match c {
            c if c.is_whitespace() || c == '\u{feff}' => {
                it.next();
            }
            '/' => {
                it.next();
                if it.peek() == Some(&'/') {
                    for c in it.by_ref() {
                        if c == '\n' {
                            break;
                        }
                    }
                } else {
                    return None;
                }
            }
            '{' => {
                it.next();
                out.push(Tok::Open);
            }
            '}' => {
                it.next();
                out.push(Tok::Close);
            }
            '"' => {
                it.next();
                let mut s = String::new();
                loop {
                    match it.next()? {
                        '"' => break,
                        '\\' => match it.next()? {
                            'n' => s.push('\n'),
                            't' => s.push('\t'),
                            '\\' => s.push('\\'),
                            '"' => s.push('"'),
                            other => {
                                s.push('\\');
                                s.push(other);
                            }
                        },
                        ch => s.push(ch),
                    }
                }
                out.push(Tok::Text(s));
            }
            _ => {
                let mut s = String::new();
                while let Some(&c) = it.peek() {
                    if c.is_whitespace() || matches!(c, '{' | '}' | '"') {
                        break;
                    }
                    s.push(c);
                    it.next();
                }
                out.push(Tok::Text(s));
            }
        }
    }
    Some(out)
}

fn parse_block(toks: &[Tok], pos: &mut usize, depth: usize, top: bool) -> Option<Node> {
    if depth > MAX_DEPTH {
        return None;
    }
    let mut items = Vec::new();
    loop {
        match toks.get(*pos) {
            None => return if top { Some(Node::Block(items)) } else { None },
            Some(Tok::Close) => {
                if top {
                    return None;
                }
                *pos += 1;
                return Some(Node::Block(items));
            }
            Some(Tok::Open) => return None,
            Some(Tok::Text(key)) => {
                *pos += 1;
                match toks.get(*pos)? {
                    Tok::Text(v) => {
                        items.push((key.clone(), Node::Str(v.clone())));
                        *pos += 1;
                    }
                    Tok::Open => {
                        *pos += 1;
                        let child = parse_block(toks, pos, depth + 1, false)?;
                        items.push((key.clone(), child));
                    }
                    Tok::Close => return None,
                }
            }
        }
    }
}

/// Parse VDF text into a root block; `None` when malformed.
#[must_use]
pub fn parse(text: &str) -> Option<Node> {
    let toks = tokenize(text)?;
    let mut pos = 0;
    parse_block(&toks, &mut pos, 0, true)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn vdf_parses_nested_blocks() {
        let n = parse(
            "\"libraryfolders\"\n{\n\t\"0\"\n\t{\n\t\t\"path\"\t\t\"C:\\\\Steam\"\n\t\t\"apps\"\n\t\t{\n\t\t\t\"10\"\t\"123\"\n\t\t}\n\t}\n\t\"1\"\n\t{\n\t\t\"path\"\t\t\"D:\\\\SteamLibrary\"\n\t}\n}\n",
        )
        .unwrap();
        let lf = n.get("LibraryFolders").unwrap();
        let paths: Vec<&str> = lf
            .children()
            .filter_map(|(_, c)| c.get("path").and_then(Node::str))
            .collect();
        assert_eq!(paths, vec!["C:\\Steam", "D:\\SteamLibrary"]);
        assert_eq!(
            lf.get("0")
                .unwrap()
                .get("apps")
                .unwrap()
                .get("10")
                .unwrap()
                .str(),
            Some("123")
        );
    }

    #[test]
    fn vdf_unescapes_paths() {
        let n = parse(r#""a" { "path"  "C:\\Games\\Steam" "q" "say \"hi\"" }"#).unwrap();
        let a = n.get("a").unwrap();
        assert_eq!(a.get("path").unwrap().str(), Some("C:\\Games\\Steam"));
        assert_eq!(a.get("q").unwrap().str(), Some("say \"hi\""));
    }

    #[test]
    fn vdf_rejects_unbalanced_braces() {
        assert!(parse(r#""a" { "b" "c""#).is_none());
        assert!(parse(r#""a" { "b" "c" } }"#).is_none());
        assert!(parse(r#""a" { "b" "#).is_none());
        assert!(parse("\"unterminated").is_none());
    }

    #[test]
    fn vdf_deep_nesting_is_rejected_not_a_panic() {
        // Balanced, so only the depth cap can refuse it.
        let deep = |n: usize| format!("{}{}", "\"a\" {".repeat(n), "}".repeat(n));
        assert!(parse(&deep(MAX_DEPTH - 1)).is_some());
        assert!(parse(&deep(1000)).is_none());
    }
}
